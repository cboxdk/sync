<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
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
        $name = $driver === 'mysql' ? 'VARCHAR(150) COLLATE utf8mb4_bin' : 'TEXT';
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
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sync_outbox_sequences (
            replica_id $name NOT NULL,
            space $name NOT NULL,
            assigned BIGINT NOT NULL,
            PRIMARY KEY (replica_id, space)
        )");
    }

    public function append(Mutation $mutation): void
    {
        $position = (int) ($this->scalar('SELECT COALESCE(MAX(queued_at), 0) FROM sync_outbox', []) ?? '0') + 1;
        $this->run('INSERT INTO sync_outbox (mutation_id, replica_id, space, entity_type, queued_at, payload, abandoned_reason) VALUES (?, ?, ?, ?, ?, ?, NULL)', [
            $mutation->id, $mutation->replica->id, $mutation->entity->space, $mutation->entity->type, $position, Payload::encode($mutation),
        ]);
    }

    public function rekey(EntityKey $from, EntityKey $to): void
    {
        // The space and type are columns, the id is only inside the payload, so
        // the rows are narrowed by column and then matched exactly on the key.
        $rows = $this->rows(
            'SELECT mutation_id, payload FROM sync_outbox WHERE space = ? AND entity_type = ? AND abandoned_reason IS NULL',
            [$from->space, $from->type],
        );

        foreach ($rows as $row) {
            $mutation = Payload::decode($row[1], Mutation::class);
            if (! $mutation->entity->equals($from)) {
                continue;
            }
            $this->run('UPDATE sync_outbox SET space = ?, entity_type = ?, payload = ? WHERE mutation_id = ?', [
                $to->space, $to->type, Payload::encode($mutation->withEntity($to)), $row[0],
            ]);
        }
    }

    public function head(?string $entityType = null): ?Mutation
    {
        $sql = 'SELECT payload FROM sync_outbox WHERE abandoned_reason IS NULL';
        $bindings = [];
        if ($entityType !== null) {
            $sql .= ' AND entity_type = ?';
            $bindings[] = $entityType;
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

    public function acknowledge(string $mutationId): void
    {
        $this->run('DELETE FROM sync_outbox WHERE mutation_id = ? AND abandoned_reason IS NULL', [$mutationId]);
    }

    public function abandon(string $mutationId, string $reason): void
    {
        $this->run('UPDATE sync_outbox SET abandoned_reason = ? WHERE mutation_id = ?', [$reason, $mutationId]);
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
