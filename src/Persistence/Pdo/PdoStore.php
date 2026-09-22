<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence\Pdo;

use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\PullPage;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Identifier;
use Cbox\Sync\ValueObjects\Replica;

/**
 * Durable store over PDO for SQLite, MySQL 8+ and PostgreSQL.
 *
 * Every mutation takes the space row's write lock as its first statement, so
 * writers in a space are serialized. That is what makes commit sequences
 * gapless and their numbering agree with the order they become visible; an
 * auto-increment cannot promise either. The cost is one concurrent writer per
 * space, which is the consistency boundary anyway.
 */
class PdoStore implements Store
{
    protected PdoSchema $schema;

    private bool $active = false;

    public function __construct(private \PDO $pdo, ?PdoSchema $schema = null)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = $schema ?? PdoSchema::forConnection($this->pdo);
    }

    /**
     * Resolved per call, never captured.
     *
     * A host whose framework owns the connection - and can replace it after a
     * reconnect while this store lives on, which is what happens under a
     * long-running worker - must override this. Holding one handle there means
     * the transaction is opened on the framework's new connection while the
     * writes go to the dead one, and the rollback rolls back nothing. That is
     * silent partial persistence, with no error anywhere.
     */
    protected function connection(): \PDO
    {
        return $this->pdo;
    }

    public function migrate(): void
    {
        $this->schema->install($this->connection());
    }

    public function transaction(string $space, \Closure $callback): mixed
    {
        if ($space === '') {
            throw new InvalidRequest('Transaction space must not be empty');
        }
        if ($this->active) {
            throw new TransientFailure('Nested or concurrent transaction is unsupported');
        }
        // Before the space row is created: MySQL's INSERT IGNORE would store
        // an over-long or malformed name truncated, as a different space.
        Identifier::check($space, 'space');
        $this->ensureSpace($space);
        $this->active = true;
        try {
            try {
                $this->begin();
            } catch (\PDOException $failure) {
                // SQLite's BEGIN IMMEDIATE waits for the write lock here.
                if (self::isContention($failure)) {
                    throw new TransientFailure('The space is busy; retry the same mutation', previous: $failure);
                }

                throw $failure;
            }
            try {
                $result = $this->underLock($space, $callback);
                $this->commit();

                return $result;
            } catch (\Throwable $failure) {
                try {
                    $this->rollback();
                } catch (\PDOException) {
                    // The database may have ended the transaction itself - a
                    // deadlock does on MySQL - and the failure that matters is
                    // the one that got us here.
                }

                // Two writers that each hold what the other needs: nothing was
                // committed, and the same mutation may simply be sent again.
                if ($failure instanceof \PDOException && self::isContention($failure)) {
                    throw new TransientFailure('The space is busy; retry the same mutation', previous: $failure);
                }

                throw $failure;
            }
        } finally {
            $this->active = false;
        }
    }

    /**
     * Transaction control, separated so a host that owns its own transaction
     * manager can drive it instead of issuing raw SQL behind its back.
     */
    protected function begin(): void
    {
        if ($this->schema->driver === PdoSchema::MYSQL) {
            // READ COMMITTED: every statement sees the latest committed state,
            // and a lookup that finds nothing does not lock the gap where the
            // row would be. MySQL's default, REPEATABLE READ, did both wrong
            // here - a snapshot older than the space lock, and gap locks that
            // made writers in DIFFERENT spaces deadlock on shared indexes.
            $this->connection()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->connection()->exec($this->schema->beginStatement());
    }

    /**
     * Whether the ledger has to use locking reads to see the latest state -
     * only when the engine runs inside a transaction someone else began, whose
     * isolation this store could not choose. Adapters that open a savepoint in
     * a host's transaction override this.
     */
    protected function needsLockingReads(): bool
    {
        return false;
    }

    /** A deadlock or a lock wait that timed out, on any driver. */
    protected static function isContention(\PDOException $failure): bool
    {
        $state = (string) $failure->getCode();
        $driverCode = $failure->errorInfo[1] ?? null;

        if ($state === 'HY000' && in_array($driverCode, [5, 6], true)) {
            // SQLITE_BUSY and SQLITE_LOCKED: another connection holds the
            // database's write lock past the busy timeout.
            return true;
        }

        return in_array($state, ['40001', '40P01', '55P03'], true) || in_array($driverCode, [1213, 1205], true);
    }

    protected function commit(): void
    {
        $this->connection()->exec('COMMIT');
    }

    protected function rollback(): void
    {
        $this->connection()->exec('ROLLBACK');
    }

    protected function ledger(string $space): Ledger
    {
        return new PdoLedger($this->connection(), $this->schema, $space, $this->needsLockingReads());
    }

    /** Adapter hook; failure here rolls back even results and acknowledgements. */
    protected function beforeCommit(string $space): void {}

    /**
     * Create the space's row if it is not there, as one statement that cannot
     * fail on a race. Catching the duplicate instead was harmless on its own
     * connection and fatal inside a host's transaction on PostgreSQL, which
     * aborts the whole transaction on any error - so the first two writes to a
     * new tenant at the same moment failed.
     */
    protected function ensureSpace(string $space): void
    {
        if ($this->scalar('SELECT 1 FROM sync_spaces WHERE space = ?', [$space]) !== null) {
            return;
        }
        $this->run(match ($this->schema->driver) {
            PdoSchema::MYSQL => 'INSERT IGNORE INTO sync_spaces (space, commit_sequence, retained_from) VALUES (?, 0, 1)',
            default => 'INSERT INTO sync_spaces (space, commit_sequence, retained_from) VALUES (?, 0, 1) ON CONFLICT (space) DO NOTHING',
        }, [$space]);
    }

    /**
     * The part of a transaction that runs under the space lock. Separate so an
     * adapter that lets its framework own the transaction can run just this.
     *
     * @template TResult
     *
     * @param  \Closure(Ledger): TResult  $callback
     * @return TResult
     */
    protected function underLock(string $space, \Closure $callback): mixed
    {
        $this->lockSpace($space);
        $result = $callback($this->ledger($space));
        $this->beforeCommit($space);

        return $result;
    }

    private function lockSpace(string $space): void
    {
        $sql = 'SELECT commit_sequence FROM sync_spaces WHERE space = ?';
        if ($this->schema->locksRows()) {
            $sql .= ' FOR UPDATE';
        }
        if ($this->scalar($sql, [$space]) === null) {
            // Locking nothing would let every writer through at once.
            throw new \LogicException(sprintf('The space row for "%s" is missing; it cannot be locked.', $space));
        }
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        $payload = $this->scalar('SELECT payload FROM sync_records WHERE space = ? AND entity_type = ? AND entity_id = ?', [$entity->space, $entity->type, $entity->id]);

        return $payload === null ? null : Payload::decode($payload, EntityRecord::class);
    }

    public function receipt(string $mutationId): ?Receipt
    {
        $payload = $this->scalar('SELECT payload FROM sync_receipts WHERE mutation_id = ?', [$mutationId]);

        return $payload === null ? null : Payload::decode($payload, Receipt::class);
    }

    public function group(string $id): ?ConflictGroup
    {
        $payload = $this->scalar('SELECT payload FROM sync_conflict_groups WHERE id = ?', [$id]);

        return $payload === null ? null : Payload::decode($payload, ConflictGroup::class);
    }

    public function openGroups(EntityKey $entity): array
    {
        $rows = $this->select(
            'SELECT payload FROM sync_conflict_groups WHERE space = ? AND entity_type = ? AND entity_id = ? AND open_key IS NOT NULL ORDER BY id',
            [$entity->space, $entity->type, $entity->id],
        );
        $groups = [];
        foreach ($rows as $row) {
            $groups[] = Payload::decode($row, ConflictGroup::class);
        }

        return $groups;
    }

    public function acknowledged(string $space, Replica $replica): int
    {
        $value = $this->scalar('SELECT acknowledged FROM sync_streams WHERE space = ? AND replica_id = ?', [$space, $replica->id]);

        return $value === null ? 0 : (int) $value;
    }

    public function watermark(string $space): CommitSequence
    {
        $value = $this->scalar('SELECT commit_sequence FROM sync_spaces WHERE space = ?', [$space]);

        return new CommitSequence($value === null ? 0 : (int) $value);
    }

    public function retainedFrom(string $space): CommitSequence
    {
        if ($this->watermark($space)->value === 0) {
            return new CommitSequence;
        }
        $value = $this->scalar('SELECT retained_from FROM sync_spaces WHERE space = ?', [$space]);

        return new CommitSequence($value === null ? 1 : (int) $value);
    }

    /**
     * Drops every commit below $from. Cursors behind the new horizon can only
     * be recovered by a bootstrap.
     *
     * Atomic, because a reader that saw the old horizon and the new set of
     * commits would advance its cursor straight past the ones just removed.
     */
    public function prune(string $space, CommitSequence $from): void
    {
        $this->transaction($space, function () use ($space, $from): bool {
            $this->run('UPDATE sync_spaces SET retained_from = ? WHERE space = ? AND retained_from < ?', [$from->value, $space, $from->value]);
            $this->run('DELETE FROM sync_commits WHERE space = ? AND sequence < ?', [$space, $from->value]);
            // Exactly the positions whose answer is about to go, per stream -
            // not everything a stream has acknowledged, which turned the
            // first retention run into a wall for any writer behind it.
            $highest = [];
            foreach ($this->pairs('SELECT replica_id, MAX(sequence) FROM sync_receipts WHERE space = ? AND commit_sequence < ? AND sequence IS NOT NULL GROUP BY replica_id', [$space, $from->value]) as [$replica, $sequence]) {
                // Keyed with a prefix: a numeric replica id would otherwise
                // become an integer array key and be bound as a number.
                $highest['r'.$replica] = max($highest['r'.$replica] ?? 0, (int) $sequence);
            }
            // Receipts from before those columns existed say it only inside.
            foreach ($this->pairs('SELECT mutation_id, payload FROM sync_receipts WHERE space = ? AND commit_sequence < ? AND sequence IS NULL', [$space, $from->value]) as [, $payload]) {
                $receipt = Payload::decode($payload, Receipt::class);
                $replica = $receipt->mutation->replica->id;
                $highest['r'.$replica] = max($highest['r'.$replica] ?? 0, $receipt->mutation->sequence->value);
            }
            foreach ($highest as $key => $sequence) {
                $this->run('UPDATE sync_streams SET pruned_through = ? WHERE space = ? AND replica_id = ? AND pruned_through < ?', [$sequence, $space, substr($key, 1), $sequence]);
            }
            $this->run('DELETE FROM sync_receipts WHERE space = ? AND commit_sequence < ?', [$space, $from->value]);

            return true;
        });
    }

    public function commitsAfter(string $space, int $after, int $limit, ?string $entityType = null): array
    {
        if ($after < 0 || $limit < 1) {
            throw new InvalidRequest('Invalid commit cursor or budget');
        }
        $this->guardHorizon($space, $after);
        $commits = $this->commitRows($space, $after, $limit, $entityType);
        // Re-checked after the read. A prune between the first check and the
        // query removes commits the reader then never sees, and it would
        // advance its cursor past them as though they had been delivered.
        $this->guardHorizon($space, $after);

        return $commits;
    }

    public function scanRecords(string $space, ?EntityKey $after, int $limit, ?RecordCriteria $criteria = null): array
    {
        if ($limit < 1) {
            throw new InvalidRequest('Invalid scan limit');
        }
        $predicates = $criteria === null ? [] : $criteria->predicates;

        // Drive from sync_fields when there is a value to match. Anchored on
        // sync_records instead, the planner probes the field table once per
        // record in the space, so a page of a selective view costs O(space):
        // measured 331ms for one page of a 32,000-record space, against 0.05ms
        // when the index can both match and order.
        $driving = null;
        foreach ($predicates as $index => $predicate) {
            if ($predicate->expected->exists) {
                $driving = $index;
                break;
            }
        }

        $deleted = $this->schema->driver === PdoSchema::PGSQL ? 'false' : 0;

        if ($driving !== null) {
            $lead = $predicates[$driving];
            $sql = 'SELECT r.payload FROM sync_fields f'
                .' JOIN sync_records r ON r.space = f.space AND r.entity_type = f.entity_type AND r.entity_id = f.entity_id'
                .' WHERE f.space = ? AND f.field = ? AND f.value_hash = ? AND r.deleted = ?';
            $bindings = [$space, $lead->field, Payload::fieldHash($lead->expected), $deleted];
            $alias = 'r.';
            $order = 'f.entity_type, f.entity_id';
            $keyset = 'f.entity_type, f.entity_id';
        } else {
            $sql = 'SELECT payload FROM sync_records WHERE space = ? AND deleted = ?';
            $bindings = [$space, $deleted];
            $alias = '';
            $order = 'entity_type, entity_id';
            $keyset = 'entity_type, entity_id';
        }

        if ($criteria?->entityType !== null) {
            $sql .= ' AND '.$alias.'entity_type = ?';
            $bindings[] = $criteria->entityType;
        }
        foreach ($predicates as $index => $predicate) {
            if ($index === $driving) {
                continue;
            }
            $outer = $alias === '' ? 'sync_records' : 'r';
            $row = 'FROM sync_fields g WHERE g.space = '.$outer.'.space AND g.entity_type = '.$outer.'.entity_type AND g.entity_id = '.$outer.'.entity_id AND g.field = ?';
            if ($predicate->expected->exists) {
                $sql .= ' AND EXISTS (SELECT 1 '.$row.' AND g.value_hash = ?)';
            } else {
                // "has no value" must also match a field that was never set,
                // which has no row at all. Asserting a matching row would miss
                // exactly those records, and a bootstrap that omits them still
                // advances its cursor - so the delta never repairs it either.
                $sql .= ' AND NOT EXISTS (SELECT 1 '.$row.' AND g.value_hash <> ?)';
            }
            $bindings[] = $predicate->field;
            $bindings[] = Payload::fieldHash($predicate->expected);
        }
        if ($after !== null) {
            $sql .= ' AND ('.$keyset.') > (?, ?)';
            $bindings[] = $after->type;
            $bindings[] = $after->id;
        }
        $sql .= ' ORDER BY '.$order.' LIMIT '.$limit;

        $records = [];
        foreach ($this->select($sql, $bindings) as $row) {
            $records[] = Payload::decode($row, EntityRecord::class);
        }

        return $records;
    }

    public function pull(string $space, int $after = 0, int $limit = 100): PullPage
    {
        $watermark = $this->watermark($space)->value;
        if ($after < 0 || $after > $watermark || $limit < 1) {
            throw new InvalidRequest('Invalid pull cursor or limit');
        }
        $this->guardHorizon($space, $after);

        // Every commit carries at least one change, so no more than $limit of
        // them can fit the change budget.
        $candidates = $this->commitRows($space, $after, $limit + 1);
        $this->guardHorizon($space, $after);
        $page = [];
        $size = 0;
        $cursor = $after;
        foreach ($candidates as $commit) {
            if ($page !== [] && $size + count($commit->changes) > $limit) {
                break;
            }
            $page[] = $commit;
            $size += count($commit->changes);
            $cursor = $commit->sequence->value;
        }

        return new PullPage($page, new CommitSequence($cursor), $cursor < $watermark);
    }

    private function guardHorizon(string $space, int $after): void
    {
        $retainedFrom = $this->retainedFrom($space)->value;
        if ($retainedFrom > 0 && $after < $retainedFrom - 1) {
            throw new HistoryUnavailable($space, new CommitSequence($after), new CommitSequence($retainedFrom));
        }
    }

    /** @return list<Commit> */
    private function commitRows(string $space, int $after, int $limit, ?string $entityType = null): array
    {
        if ($entityType === null) {
            $rows = $this->select('SELECT payload FROM sync_commits WHERE space = ? AND sequence > ? ORDER BY sequence LIMIT '.$limit, [$space, $after]);
        } else {
            // A row written before entity_type existed has no type to compare,
            // and skipping it would silently drop history the reader is owed.
            //
            // Two ranges merged, not `entity_type = ? OR entity_type IS NULL`:
            // the OR stops SQLite using the (space, entity_type, sequence)
            // index at all, and the read falls back to walking the space's
            // whole log tail - the cost this column exists to avoid. Each
            // branch is limited on its own, so neither can return more than
            // the page.
            $branch = 'SELECT payload, sequence FROM (SELECT payload, sequence FROM sync_commits WHERE space = ? AND entity_type %s AND sequence > ? ORDER BY sequence LIMIT '.$limit.') %s';
            $rows = $this->select(
                'SELECT payload FROM ('.sprintf($branch, '= ?', 'typed').' UNION ALL '.sprintf($branch, 'IS NULL', 'untyped').') merged ORDER BY sequence LIMIT '.$limit,
                [$space, $entityType, $after, $space, $after],
            );
        }
        $commits = [];
        foreach ($rows as $row) {
            $commits[] = Payload::decode($row, Commit::class);
        }

        return $commits;
    }

    /**
     * @param  list<string|int|null>  $bindings
     * @return list<string>
     */
    private function select(string $sql, array $bindings): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($bindings);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN, 0) as $value) {
            if (! is_string($value)) {
                throw new \LogicException('Expected a text column');
            }
            $rows[] = $value;
        }

        return $rows;
    }

    /** @param list<string|int|null> $bindings */
    private function scalar(string $sql, array $bindings): ?string
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($bindings);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @param list<string|int|null> $bindings */
    private function run(string $sql, array $bindings): void
    {
        $this->connection()->prepare($sql)->execute($bindings);
    }

    /**
     * @param  list<string|int|null>  $bindings
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(string $sql, array $bindings): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($bindings);
        $pairs = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (is_array($row) && is_scalar($row[0] ?? null) && is_scalar($row[1] ?? null)) {
                $pairs[] = [(string) $row[0], (string) $row[1]];
            }
        }

        return $pairs;
    }
}
