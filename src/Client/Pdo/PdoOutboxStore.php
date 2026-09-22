<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Persistence\Pdo\Payload;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

/**
 * The durable queue. Sequences come from a high-water row rather than from the
 * queue itself, so a number is never reused after its mutation is acknowledged
 * and removed - the server treats a repeated sequence as a protocol error.
 */
class PdoOutboxStore implements OutboxStore
{
    private bool $active = false;

    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function migrate(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $name = $driver === 'mysql' ? 'VARCHAR(150) COLLATE utf8mb4_0900_bin' : 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sync_outbox (
            mutation_id $name NOT NULL,
            replica_id $name NOT NULL,
            space $name NOT NULL,
            entity_type $name NOT NULL,
            queued_at BIGINT NOT NULL,
            payload $text NOT NULL,
            abandoned_reason $text NULL,
            PRIMARY KEY (mutation_id)
        )");
        // head() is called once per mutation while draining, and without this
        // every call scans the table and sorts it in a temp b-tree. Measured on
        // a 5,000-deep backlog: 32 seconds of local scanning before a single
        // request goes out, on the device, on the exact day the user most needs
        // it to work.
        $this->index('sync_outbox_head', 'sync_outbox', 'entity_type, queued_at, mutation_id');
        // append() takes the next position with MAX(queued_at), which the index
        // above cannot serve because entity_type leads it. Without this one,
        // queueing is O(n) per write and a long offline session gets slower the
        // longer it lasts.
        $this->index('sync_outbox_position', 'sync_outbox', 'queued_at');
        // A push drains one type in one space.
        $this->index('sync_outbox_stream', 'sync_outbox', 'entity_type, space, queued_at, mutation_id');
        // The entity id as a column, so a rename finds the rows it concerns
        // by index instead of decoding every queued write of that type - which
        // made draining a long run of offline creates quadratic. Added to an
        // existing device database in place; rows from before it are NULL and
        // are still matched by decoding.
        $columns = $this->columns('sync_outbox');
        if (! in_array('entity_id', $columns, true)) {
            $this->pdo->exec("ALTER TABLE sync_outbox ADD COLUMN entity_id $name NULL");
        }
        if (! in_array('attempted', $columns, true)) {
            $this->pdo->exec('ALTER TABLE sync_outbox ADD COLUMN attempted SMALLINT NOT NULL DEFAULT 0');
        }
        if (! in_array('numbered', $columns, true)) {
            $this->pdo->exec('ALTER TABLE sync_outbox ADD COLUMN numbered SMALLINT NOT NULL DEFAULT 0');
        }
        if (! in_array('kind', $columns, true)) {
            $this->pdo->exec("ALTER TABLE sync_outbox ADD COLUMN kind $name NULL");
        }
        if (! in_array('dismissed', $columns, true)) {
            $this->pdo->exec('ALTER TABLE sync_outbox ADD COLUMN dismissed SMALLINT NOT NULL DEFAULT 0');
        }
        if (! in_array('sends', $columns, true)) {
            // An earlier release kept no record of its sendings, so any write
            // already queued may have gone out without an answer: one, by the
            // column's own default, in the same statement that adds it - a
            // crash between two statements left them at none. New writes are
            // appended with an explicit zero.
            $this->pdo->exec('ALTER TABLE sync_outbox ADD COLUMN sends INTEGER NOT NULL DEFAULT 1');
        }
        // A write an earlier release sent and never heard back about carries
        // the placeholder number in its payload: that release numbered afresh
        // on every attempt, and only flagged the row. Trusting that payload's
        // number resent it as 1. Unflagged, it is numbered on its next send
        // exactly as the earlier release would have numbered it.
        $this->run('UPDATE sync_outbox SET attempted = 0 WHERE attempted = 1 AND numbered = 0', []);
        // Rows queued before the id column existed get it now, once, so
        // everything that finds a record's writes by id sees them too.
        foreach ($this->rows('SELECT mutation_id, payload FROM sync_outbox WHERE entity_id IS NULL', []) as [$id, $payload]) {
            $this->run('UPDATE sync_outbox SET entity_id = ? WHERE mutation_id = ?', [Payload::decode($payload, Mutation::class)->entity->id, $id]);
        }
        // Rows queued before the kind column get it now, once.
        foreach ($this->rows('SELECT mutation_id, payload FROM sync_outbox WHERE kind IS NULL', []) as [$id, $payload]) {
            $this->run('UPDATE sync_outbox SET kind = ? WHERE mutation_id = ?', [Payload::decode($payload, Mutation::class)->kind->value, $id]);
        }
        $this->index('sync_outbox_entity', 'sync_outbox', 'space, entity_type, entity_id');
        $this->index('sync_outbox_create', 'sync_outbox', 'entity_type, entity_id, kind, queued_at');
        $this->index('sync_outbox_record', 'sync_outbox', 'entity_type, entity_id');
        // What each handle this device created under became. Written in the
        // same transaction as the acknowledgement, so a crash cannot leave the
        // create gone and nothing that says what it was called.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sync_outbox_names (
            space $name NOT NULL,
            entity_type $name NOT NULL,
            handle $name NOT NULL,
            name $name NOT NULL,
            PRIMARY KEY (space, entity_type, handle)
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sync_outbox_sequences (
            replica_id $name NOT NULL,
            space $name NOT NULL,
            assigned BIGINT NOT NULL,
            PRIMARY KEY (replica_id, space)
        )");
        foreach (['sync_outbox', 'sync_outbox_names', 'sync_outbox_sequences'] as $table) {
            MysqlCollation::repair($this->pdo, $table);
        }
    }

    public function append(Mutation $mutation): void
    {
        $position = (int) ($this->scalar('SELECT COALESCE(MAX(queued_at), 0) FROM sync_outbox', []) ?? '0') + 1;

        try {
            $this->run('INSERT INTO sync_outbox (mutation_id, replica_id, space, entity_type, entity_id, kind, queued_at, payload, abandoned_reason, sends) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)', [
                $mutation->id, $mutation->replica->id, $mutation->entity->space, $mutation->entity->type, $mutation->entity->id, $mutation->kind->value, $position, Payload::encode($mutation),
            ]);
        } catch (\PDOException $exception) {
            // An abandoned row keeps its id, so re-queuing under the same
            // identity lands here. Both stores refuse it, and they refuse it
            // the same way: a raw driver exception escaping the package's own
            // hierarchy left a caller unable to tell it from a disk error.
            if (! in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            throw new InvalidRequest('Mutation identity is already queued: '.$mutation->id, previous: $exception);
        }
    }

    public function rekey(EntityKey $from, EntityKey $to, bool $creates = true): void
    {
        $this->run('DELETE FROM sync_outbox_names WHERE space = ? AND entity_type = ? AND handle = ?', [$from->space, $from->type, $from->id]);
        $this->run('INSERT INTO sync_outbox_names (space, entity_type, handle, name) VALUES (?, ?, ?, ?)', [$from->space, $from->type, $from->id, $to->id]);

        // Found by index on the id column; a row queued before that column
        // existed has NULL there and is matched by decoding it.
        $rows = $this->rows(
            'SELECT mutation_id, payload FROM sync_outbox WHERE space = ? AND entity_type = ? AND (entity_id = ? OR entity_id IS NULL) AND abandoned_reason IS NULL',
            [$from->space, $from->type, $from->id],
        );

        foreach ($rows as $row) {
            $mutation = Payload::decode($row[1], Mutation::class);
            // A create only when asked: after the server named one, another
            // create queued for the same handle is a record of its own.
            if (! $mutation->entity->equals($from) || (! $creates && $mutation->kind === MutationKind::Create)) {
                continue;
            }
            $this->run('UPDATE sync_outbox SET space = ?, entity_type = ?, entity_id = ?, payload = ? WHERE mutation_id = ?', [
                $to->space, $to->type, $to->id, Payload::encode($mutation->withEntity($to)), $row[0],
            ]);
        }
    }

    public function replace(Mutation $mutation): void
    {
        $this->run('UPDATE sync_outbox SET payload = ?, kind = ? WHERE mutation_id = ? AND abandoned_reason IS NULL', [
            Payload::encode($mutation), $mutation->kind->value, $mutation->id,
        ]);
    }

    public function head(?string $entityType = null, ?string $space = null): ?Mutation
    {
        $sql = 'SELECT payload FROM sync_outbox WHERE abandoned_reason IS NULL';
        $bindings = [];
        if ($entityType !== null) {
            $sql .= ' AND entity_type = ?';
            $bindings[] = $entityType;
        }
        if ($space !== null) {
            $sql .= ' AND space = ?';
            $bindings[] = $space;
        }
        $payload = $this->scalar($sql.' ORDER BY queued_at, mutation_id LIMIT 1', $bindings);

        return $payload === null ? null : Payload::decode($payload, Mutation::class);
    }

    public function acknowledged(Replica $replica, string $space): int
    {
        return (int) ($this->scalar('SELECT assigned FROM sync_outbox_sequences WHERE replica_id = ? AND space = ?', [$replica->id, $space]) ?? '0');
    }

    public function setAcknowledged(Replica $replica, string $space, int $sequence): void
    {
        // Created only when missing, by one statement that cannot fail on a
        // race. Inserting unconditionally took a shared lock on the existing
        // row on MySQL, and two hand-outs both asking for it exclusively next
        // deadlocked. Transactions here run at READ COMMITTED, so the read
        // first fixes no stale snapshot.
        if ($this->scalar('SELECT 1 FROM sync_outbox_sequences WHERE replica_id = ? AND space = ?', [$replica->id, $space]) === null) {
            $this->run(match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                'mysql' => 'INSERT IGNORE INTO sync_outbox_sequences (replica_id, space, assigned) VALUES (?, ?, 0)',
                default => 'INSERT INTO sync_outbox_sequences (replica_id, space, assigned) VALUES (?, ?, 0) ON CONFLICT (replica_id, space) DO NOTHING',
            }, [$replica->id, $space]);
        }
        $this->run('UPDATE sync_outbox_sequences SET assigned = ? WHERE replica_id = ? AND space = ? AND assigned < ?', [$sequence, $replica->id, $space, $sequence]);
    }

    public function resetAcknowledged(Replica $replica, string $space, int $sequence, int $expected): bool
    {
        $this->setAcknowledged($replica, $space, 0);
        // One statement, so the comparison and the write cannot be separated
        // by another delivery.
        $statement = $this->pdo->prepare('UPDATE sync_outbox_sequences SET assigned = ? WHERE replica_id = ? AND space = ? AND assigned = ?');
        $statement->execute([$sequence, $replica->id, $space, $expected]);
        if ($statement->rowCount() > 0) {
            return true;
        }

        // MySQL counts only rows it changed: setting it to what it already is
        // matched, too.
        return $sequence === $expected && $this->acknowledged($replica, $space) === $expected;
    }

    public function namedAs(string $entityType, string $handle): ?string
    {
        $names = [];
        foreach ($this->rows('SELECT space, name FROM sync_outbox_names WHERE entity_type = ? AND handle = ?', [$entityType, $handle]) as [, $name]) {
            $names[] = $name;
        }
        $names = array_values(array_unique($names));

        // Not array keys: a numeric name would come back an integer.
        return count($names) === 1 ? $names[0] : null;
    }

    public function firstFor(string $entityType, string $entityId): ?Mutation
    {
        $payload = $this->scalar('SELECT payload FROM sync_outbox WHERE entity_type = ? AND entity_id = ? AND abandoned_reason IS NULL ORDER BY queued_at, mutation_id LIMIT 1', [$entityType, $entityId]);

        return $payload === null ? null : Payload::decode($payload, Mutation::class);
    }

    public function createFor(string $entityType, string $entityId, ?string $space = null): ?Mutation
    {
        // The record's rows only, by index, and the earliest create picked
        // here: an ORDER BY led SQLite and PostgreSQL to walk the whole queue
        // in queue order instead, which made a long drain quadratic. A row an
        // earlier release queued has no kind yet, and is judged by its payload.
        $sql = "SELECT queued_at, mutation_id, payload FROM sync_outbox WHERE entity_type = ? AND entity_id = ? AND (kind = 'create' OR kind IS NULL) AND abandoned_reason IS NULL";
        $bindings = [$entityType, $entityId];
        if ($space !== null) {
            $sql .= ' AND space = ?';
            $bindings[] = $space;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $first = null;
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (! is_array($row) || ! is_numeric($row[0] ?? null) || ! is_string($row[1] ?? null) || ! is_string($row[2] ?? null)) {
                continue;
            }
            $mutation = Payload::decode($row[2], Mutation::class);
            $position = [(int) $row[0], $row[1]];
            if ($mutation->kind === MutationKind::Create && ($first === null || $position < $first[0])) {
                $first = [$position, $mutation];
            }
        }

        return $first[1] ?? null;
    }

    public function handleSpaces(string $entityType, string $handle): array
    {
        $spaces = [];
        foreach ($this->rows('SELECT space, name FROM sync_outbox_names WHERE entity_type = ? AND handle = ?', [$entityType, $handle]) as [$space]) {
            $spaces[] = $space;
        }
        foreach ($this->rows("SELECT space, payload FROM sync_outbox WHERE entity_type = ? AND entity_id = ? AND (kind = 'create' OR kind IS NULL)", [$entityType, $handle]) as [$space, $payload]) {
            if (Payload::decode($payload, Mutation::class)->kind === MutationKind::Create) {
                $spaces[] = $space;
            }
        }

        return array_values(array_unique($spaces));
    }

    public function recordName(EntityKey $handle, string $name): void
    {
        $this->run('DELETE FROM sync_outbox_names WHERE space = ? AND entity_type = ? AND handle = ?', [$handle->space, $handle->type, $handle->id]);
        $this->run('INSERT INTO sync_outbox_names (space, entity_type, handle, name) VALUES (?, ?, ?, ?)', [$handle->space, $handle->type, $handle->id, $name]);
    }

    public function find(string $mutationId): ?Mutation
    {
        $payload = $this->scalar('SELECT payload FROM sync_outbox WHERE mutation_id = ? AND abandoned_reason IS NULL'.$this->locking(), [$mutationId]);

        return $payload === null ? null : Payload::decode($payload, Mutation::class);
    }

    /**
     * Inside a transaction, a read of a row this transaction may write back
     * locks it, so two processes rewriting the same write - one handing it
     * out, one pointing its reference at a parent's new name - take turns,
     * and the second sees what the first wrote instead of overwriting it with
     * the copy it read before. SQLite already serializes every transaction.
     */
    private function locking(): string
    {
        return $this->pdo->inTransaction() && $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
    }

    public function markSent(Mutation $numbered): void
    {
        $this->run('UPDATE sync_outbox SET attempted = 1, numbered = 1, payload = ?, kind = ? WHERE mutation_id = ?', [Payload::encode($numbered), $numbered->kind->value, $numbered->id]);
    }

    public function unmarkSent(string $mutationId): void
    {
        $this->run('UPDATE sync_outbox SET attempted = 0 WHERE mutation_id = ?', [$mutationId]);
    }

    public function countSend(string $mutationId): void
    {
        $this->run('UPDATE sync_outbox SET sends = sends + 1 WHERE mutation_id = ?', [$mutationId]);
    }

    public function countAnswer(string $mutationId): void
    {
        $this->run('UPDATE sync_outbox SET sends = sends - 1 WHERE mutation_id = ? AND sends > 0', [$mutationId]);
    }

    public function clearSends(string $mutationId): void
    {
        $this->run('UPDATE sync_outbox SET sends = 0 WHERE mutation_id = ?', [$mutationId]);
    }

    public function unanswered(string $mutationId): int
    {
        return (int) ($this->scalar('SELECT sends FROM sync_outbox WHERE mutation_id = ?', [$mutationId]) ?? '0');
    }

    public function queuedOn(Replica $replica, string $space): array
    {
        $mutations = [];
        foreach ($this->rows('SELECT mutation_id, payload FROM sync_outbox WHERE replica_id = ? AND space = ? AND abandoned_reason IS NULL ORDER BY queued_at, mutation_id'.$this->locking(), [$replica->id, $space]) as $row) {
            $mutations[] = Payload::decode($row[1], Mutation::class);
        }

        return $mutations;
    }

    public function isSent(string $mutationId): bool
    {
        return $this->scalar('SELECT attempted FROM sync_outbox WHERE mutation_id = ?', [$mutationId]) === '1';
    }

    public function lockStream(Replica $replica, string $space): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return;
        }
        $this->setAcknowledged($replica, $space, 0);
        $this->scalar('SELECT assigned FROM sync_outbox_sequences WHERE replica_id = ? AND space = ? FOR UPDATE', [$replica->id, $space]);
    }

    public function inFlight(Replica $replica, string $space): ?Mutation
    {
        $payload = $this->scalar('SELECT payload FROM sync_outbox WHERE replica_id = ? AND space = ? AND attempted = 1 AND abandoned_reason IS NULL ORDER BY queued_at, mutation_id LIMIT 1', [$replica->id, $space]);

        return $payload === null ? null : Payload::decode($payload, Mutation::class);
    }

    public function relabel(string $entityType, string $from, string $to): void
    {
        foreach ($this->rows('SELECT mutation_id, payload FROM sync_outbox WHERE entity_type = ? AND space = ? AND abandoned_reason IS NULL', [$entityType, $from]) as [$id, $payload]) {
            $mutation = Payload::decode($payload, Mutation::class);
            $this->run('UPDATE sync_outbox SET space = ?, payload = ? WHERE mutation_id = ?', [
                $to, Payload::encode($mutation->withEntity(new EntityKey($to, $entityType, $mutation->entity->id))), $id,
            ]);
        }
    }

    public function nameOf(EntityKey $handle): ?EntityKey
    {
        $name = $this->scalar('SELECT name FROM sync_outbox_names WHERE space = ? AND entity_type = ? AND handle = ?', [$handle->space, $handle->type, $handle->id]);

        return $name === null ? null : new EntityKey($handle->space, $handle->type, $name);
    }

    public function acknowledge(string $mutationId): void
    {
        $this->run('DELETE FROM sync_outbox WHERE mutation_id = ? AND abandoned_reason IS NULL', [$mutationId]);
    }

    public function abandon(string $mutationId, string $reason): void
    {
        // Only a write still queued: a late refusal of one already abandoned,
        // or dismissed, keeps the first answer and is not reported again.
        $this->run('UPDATE sync_outbox SET abandoned_reason = ? WHERE mutation_id = ? AND abandoned_reason IS NULL', [$reason, $mutationId]);
    }

    public function dismiss(string $mutationId): void
    {
        $this->run('DELETE FROM sync_outbox WHERE mutation_id = ? AND abandoned_reason IS NOT NULL', [$mutationId]);
    }

    public function setReason(string $mutationId, string $reason): void
    {
        $this->run('UPDATE sync_outbox SET abandoned_reason = ? WHERE mutation_id = ? AND abandoned_reason IS NOT NULL', [$reason, $mutationId]);
    }

    public function abandonedCreate(string $entityType, string $entityId, ?string $space = null): ?array
    {
        // Reported before dismissed, then the one queued last.
        $sql = 'SELECT payload, abandoned_reason FROM sync_outbox WHERE entity_type = ? AND entity_id = ? AND abandoned_reason IS NOT NULL';
        $bindings = [$entityType, $entityId];
        if ($space !== null) {
            $sql .= ' AND space = ?';
            $bindings[] = $space;
        }
        foreach ($this->rows($sql.' ORDER BY dismissed, queued_at DESC, mutation_id DESC', $bindings) as [$payload, $reason]) {
            $mutation = Payload::decode($payload, Mutation::class);
            if ($mutation->kind === MutationKind::Create) {
                return ['mutation' => $mutation, 'reason' => $reason];
            }
        }

        return null;
    }

    public function markDismissed(string $mutationId): void
    {
        $this->run('UPDATE sync_outbox SET dismissed = 1 WHERE mutation_id = ? AND abandoned_reason IS NOT NULL', [$mutationId]);
    }

    public function forgetDismissedCreates(string $entityType, string $entityId): void
    {
        foreach ($this->rows('SELECT mutation_id, payload FROM sync_outbox WHERE entity_type = ? AND entity_id = ? AND dismissed = 1', [$entityType, $entityId]) as [$id, $payload]) {
            if (Payload::decode($payload, Mutation::class)->kind === MutationKind::Create) {
                $this->run('DELETE FROM sync_outbox WHERE mutation_id = ?', [$id]);
            }
        }
    }

    public function forget(string $mutationId): void
    {
        $this->run('DELETE FROM sync_outbox WHERE mutation_id = ?', [$mutationId]);
    }

    public function abandonedOne(string $mutationId): ?array
    {
        $statement = $this->pdo->prepare('SELECT payload, abandoned_reason FROM sync_outbox WHERE mutation_id = ? AND abandoned_reason IS NOT NULL AND dismissed = 0');
        $statement->execute([$mutationId]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        if (! is_array($row) || ! is_string($row[0] ?? null) || ! is_string($row[1] ?? null)) {
            return null;
        }

        return ['mutation' => Payload::decode($row[0], Mutation::class), 'reason' => $row[1]];
    }

    public function abandoned(): array
    {
        $statement = $this->pdo->prepare('SELECT payload, abandoned_reason FROM sync_outbox WHERE abandoned_reason IS NOT NULL AND dismissed = 0 ORDER BY queued_at, mutation_id');
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (! is_array($row) || ! is_string($row[0] ?? null) || ! is_string($row[1] ?? null)) {
                throw new \LogicException('Malformed outbox row');
            }
            $rows[] = ['mutation' => Payload::decode($row[0], Mutation::class), 'reason' => $row[1]];
        }

        return $rows;
    }

    public function pending(?string $entityType = null): int
    {
        if ($entityType === null) {
            return (int) ($this->scalar('SELECT COUNT(*) FROM sync_outbox WHERE abandoned_reason IS NULL', []) ?? '0');
        }

        return (int) ($this->scalar('SELECT COUNT(*) FROM sync_outbox WHERE abandoned_reason IS NULL AND entity_type = ?', [$entityType]) ?? '0');
    }

    /** CREATE INDEX IF NOT EXISTS, which MySQL does not have. */
    private function index(string $name, string $table, string $columns): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $lookup = $this->pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
            $lookup->execute([$table, $name]);
            if ($lookup->fetchColumn() === false) {
                $this->pdo->exec(sprintf('CREATE INDEX %s ON %s (%s)', $name, $table, $columns));
            }

            return;
        }
        $this->pdo->exec(sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (%s)', $name, $table, $columns));
    }

    /** @return list<string> Lowercased column names of one table. */
    private function columns(string $table): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        [$sql, $bindings] = match ($driver) {
            'sqlite' => ['SELECT name FROM pragma_table_info(?)', [$table]],
            'mysql' => ['SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]],
            default => ['SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]],
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $names = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    public function queued(array $entityTypes): array
    {
        if ($entityTypes === []) {
            return [];
        }
        $mutations = [];
        $sql = 'SELECT mutation_id, payload FROM sync_outbox WHERE abandoned_reason IS NULL AND attempted = 0 AND entity_type IN ('
            .implode(', ', array_fill(0, count($entityTypes), '?')).') ORDER BY queued_at, mutation_id'.$this->locking();
        foreach ($this->rows($sql, $entityTypes) as $row) {
            $mutations[] = Payload::decode($row[1], Mutation::class);
        }

        return $mutations;
    }

    public function transaction(\Closure $callback): mixed
    {
        if ($this->active) {
            throw new TransientFailure('Nested outbox transaction is unsupported');
        }
        $this->active = true;
        try {
            $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                // Every read sees the latest commit. At MySQL's default a read
                // before the stream lock fixed the snapshot, and a process
                // waiting behind another's hand-out then missed the write that
                // one had just sent and numbered a second write the same.
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            try {
                // SQLite waits for its write lock here, and that is where it
                // is busy.
                $this->pdo->exec($driver === 'sqlite' ? 'BEGIN IMMEDIATE' : 'BEGIN');
            } catch (\PDOException $failure) {
                throw self::isContention($failure) ? new TransientFailure('The outbox is busy; try again', previous: $failure) : $failure;
            }
            try {
                $result = $callback();
                $this->pdo->exec('COMMIT');

                return $result;
            } catch (\Throwable $failure) {
                $this->pdo->exec('ROLLBACK');
                // Two device processes that each hold what the other needs:
                // nothing was written, and the same call may simply be made
                // again.
                if ($failure instanceof \PDOException && self::isContention($failure)) {
                    throw new TransientFailure('The outbox is busy; try again', previous: $failure);
                }

                throw $failure;
            }
        } finally {
            $this->active = false;
        }
    }

    private static function isContention(\PDOException $failure): bool
    {
        $sqlState = $failure->errorInfo[0] ?? null;
        $state = is_string($sqlState) ? $sqlState : (string) $failure->getCode();
        $driverCode = $failure->errorInfo[1] ?? null;

        return in_array($state, ['40001', '40P01', '55P03'], true) || in_array($driverCode, [1213, 1205], true)
            || ($state === 'HY000' && in_array($driverCode, [5, 6], true));
    }

    /** @param list<string|int|null> $bindings */
    private function scalar(string $sql, array $bindings): ?string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @param  list<string|int|null>  $bindings
     * @return list<array{0: string, 1: string}>
     */
    private function rows(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row[0] ?? null;
            $payload = $row[1] ?? null;
            if ((is_string($id) || is_int($id)) && is_string($payload)) {
                $rows[] = [(string) $id, $payload];
            }
        }

        return $rows;
    }

    /** @param list<string|int|null> $bindings */
    private function run(string $sql, array $bindings): void
    {
        $this->pdo->prepare($sql)->execute($bindings);
    }
}
