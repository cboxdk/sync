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
        $this->ensureSpace($space);
        $this->active = true;
        try {
            $this->begin();
            try {
                $this->lockSpace($space);
                $result = $callback($this->ledger($space));
                $this->beforeCommit($space);
                $this->commit();

                return $result;
            } catch (\Throwable $failure) {
                $this->rollback();

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
        $this->connection()->exec($this->schema->beginStatement());
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
        return new PdoLedger($this->connection(), $this->schema, $space);
    }

    /** Adapter hook; failure here rolls back even results and acknowledgements. */
    protected function beforeCommit(string $space): void {}

    /** Outside the transaction, so a lost race is a harmless duplicate rather than a poisoned transaction. */
    private function ensureSpace(string $space): void
    {
        if ($this->scalar('SELECT 1 FROM sync_spaces WHERE space = ?', [$space]) !== null) {
            return;
        }
        try {
            $this->run('INSERT INTO sync_spaces (space, commit_sequence, retained_from) VALUES (?, 0, 1)', [$space]);
        } catch (\PDOException) {
            // Another connection created it first.
        }
    }

    private function lockSpace(string $space): void
    {
        $sql = 'SELECT commit_sequence FROM sync_spaces WHERE space = ?';
        if ($this->schema->locksRows()) {
            $sql .= ' FOR UPDATE';
        }
        $this->scalar($sql, [$space]);
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

    /** Drops every commit below $from. Cursors behind the new horizon can only be recovered by a bootstrap. */
    public function prune(string $space, CommitSequence $from): void
    {
        $this->run('DELETE FROM sync_commits WHERE space = ? AND sequence < ?', [$space, $from->value]);
        $this->run('UPDATE sync_spaces SET retained_from = ? WHERE space = ? AND retained_from < ?', [$from->value, $space, $from->value]);
    }

    public function commitsAfter(string $space, int $after, int $limit): array
    {
        if ($after < 0 || $limit < 1) {
            throw new InvalidRequest('Invalid commit cursor or budget');
        }
        $this->guardHorizon($space, $after);

        return $this->commitRows($space, $after, $limit);
    }

    public function scanRecords(string $space, ?EntityKey $after, int $limit, ?RecordCriteria $criteria = null): array
    {
        if ($limit < 1) {
            throw new InvalidRequest('Invalid scan limit');
        }
        $sql = 'SELECT payload FROM sync_records WHERE space = ? AND deleted = ?';
        $bindings = [$space, $this->schema->driver === PdoSchema::PGSQL ? 'false' : 0];
        if ($criteria?->entityType !== null) {
            $sql .= ' AND entity_type = ?';
            $bindings[] = $criteria->entityType;
        }
        foreach ($criteria === null ? [] : $criteria->predicates as $predicate) {
            $sql .= ' AND EXISTS (SELECT 1 FROM sync_fields f WHERE f.space = sync_records.space AND f.entity_type = sync_records.entity_type AND f.entity_id = sync_records.entity_id AND f.field = ? AND f.value_hash = ?)';
            $bindings[] = $predicate->field;
            $bindings[] = Payload::fieldHash($predicate->expected);
        }
        if ($after !== null) {
            $sql .= ' AND (entity_type, entity_id) > (?, ?)';
            $bindings[] = $after->type;
            $bindings[] = $after->id;
        }
        $sql .= ' ORDER BY entity_type, entity_id LIMIT '.$limit;

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
    private function commitRows(string $space, int $after, int $limit): array
    {
        $rows = $this->select(
            'SELECT payload FROM sync_commits WHERE space = ? AND sequence > ? ORDER BY sequence LIMIT '.$limit,
            [$space, $after],
        );
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
}
