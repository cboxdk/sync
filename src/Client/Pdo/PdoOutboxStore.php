<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
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
        if (! in_array('entity_id', $this->columns('sync_outbox'), true)) {
            $this->pdo->exec("ALTER TABLE sync_outbox ADD COLUMN entity_id $name NULL");
        }
        $this->index('sync_outbox_entity', 'sync_outbox', 'space, entity_type, entity_id');
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
            $this->run('INSERT INTO sync_outbox (mutation_id, replica_id, space, entity_type, entity_id, queued_at, payload, abandoned_reason) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)', [
                $mutation->id, $mutation->replica->id, $mutation->entity->space, $mutation->entity->type, $mutation->entity->id, $position, Payload::encode($mutation),
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

    public function rekey(EntityKey $from, EntityKey $to): void
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
            if (! $mutation->entity->equals($from)) {
                continue;
            }
            $this->run('UPDATE sync_outbox SET space = ?, entity_type = ?, entity_id = ?, payload = ? WHERE mutation_id = ?', [
                $to->space, $to->type, $to->id, Payload::encode($mutation->withEntity($to)), $row[0],
            ]);
        }
    }

    public function replace(Mutation $mutation): void
    {
        $this->run('UPDATE sync_outbox SET payload = ? WHERE mutation_id = ? AND abandoned_reason IS NULL', [
            Payload::encode($mutation), $mutation->id,
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
        if ($this->scalar('SELECT 1 FROM sync_outbox_sequences WHERE replica_id = ? AND space = ?', [$replica->id, $space]) === null) {
            $this->run('INSERT INTO sync_outbox_sequences (replica_id, space, assigned) VALUES (?, ?, 0)', [$replica->id, $space]);
        }
        $this->run('UPDATE sync_outbox_sequences SET assigned = ? WHERE replica_id = ? AND space = ? AND assigned < ?', [$sequence, $replica->id, $space, $sequence]);
    }

    public function resetAcknowledged(Replica $replica, string $space, int $sequence): void
    {
        $this->setAcknowledged($replica, $space, 0);
        $this->run('UPDATE sync_outbox_sequences SET assigned = ? WHERE replica_id = ? AND space = ?', [$sequence, $replica->id, $space]);
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
        $this->run('UPDATE sync_outbox SET abandoned_reason = ? WHERE mutation_id = ?', [$reason, $mutationId]);
    }

    public function dismiss(string $mutationId): void
    {
        $this->run('DELETE FROM sync_outbox WHERE mutation_id = ? AND abandoned_reason IS NOT NULL', [$mutationId]);
    }

    public function abandoned(): array
    {
        $statement = $this->pdo->prepare('SELECT payload, abandoned_reason FROM sync_outbox WHERE abandoned_reason IS NOT NULL ORDER BY queued_at, mutation_id');
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

    public function queuedKey(string $entityType, string $entityId): ?EntityKey
    {
        $space = $this->scalar('SELECT space FROM sync_outbox WHERE entity_type = ? AND entity_id = ? AND abandoned_reason IS NULL ORDER BY queued_at LIMIT 1', [$entityType, $entityId]);

        return $space === null ? null : new EntityKey($space, $entityType, $entityId);
    }

    public function queued(array $entityTypes): array
    {
        if ($entityTypes === []) {
            return [];
        }
        $mutations = [];
        $sql = 'SELECT mutation_id, payload FROM sync_outbox WHERE abandoned_reason IS NULL AND entity_type IN ('
            .implode(', ', array_fill(0, count($entityTypes), '?')).') ORDER BY queued_at, mutation_id';
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
            $this->pdo->exec($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'BEGIN IMMEDIATE' : 'BEGIN');
            try {
                $result = $callback();
                $this->pdo->exec('COMMIT');

                return $result;
            } catch (\Throwable $failure) {
                $this->pdo->exec('ROLLBACK');

                throw $failure;
            }
        } finally {
            $this->active = false;
        }
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
