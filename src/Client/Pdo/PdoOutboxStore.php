<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Persistence\Pdo\Payload;
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
            queued_at BIGINT NOT NULL,
            payload $text NOT NULL,
            abandoned_reason $text NULL,
            PRIMARY KEY (mutation_id)
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sync_outbox_sequences (
            replica_id $name NOT NULL,
            assigned BIGINT NOT NULL,
            PRIMARY KEY (replica_id)
        )");
    }

    public function append(Mutation $mutation): void
    {
        $position = (int) ($this->scalar('SELECT COALESCE(MAX(queued_at), 0) FROM sync_outbox', []) ?? '0') + 1;
        $this->run('INSERT INTO sync_outbox (mutation_id, replica_id, queued_at, payload, abandoned_reason) VALUES (?, ?, ?, ?, NULL)', [
            $mutation->id, $mutation->replica->id, $position, Payload::encode($mutation),
        ]);
    }

    public function head(): ?Mutation
    {
        $payload = $this->scalar('SELECT payload FROM sync_outbox WHERE abandoned_reason IS NULL ORDER BY queued_at, mutation_id LIMIT 1', []);

        return $payload === null ? null : Payload::decode($payload, Mutation::class);
    }

    public function acknowledged(Replica $replica): int
    {
        return (int) ($this->scalar('SELECT assigned FROM sync_outbox_sequences WHERE replica_id = ?', [$replica->id]) ?? '0');
    }

    public function setAcknowledged(Replica $replica, int $sequence): void
    {
        if ($this->scalar('SELECT 1 FROM sync_outbox_sequences WHERE replica_id = ?', [$replica->id]) === null) {
            $this->run('INSERT INTO sync_outbox_sequences (replica_id, assigned) VALUES (?, 0)', [$replica->id]);
        }
        $this->run('UPDATE sync_outbox_sequences SET assigned = ? WHERE replica_id = ? AND assigned < ?', [$sequence, $replica->id, $sequence]);
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

    public function pending(): int
    {
        return (int) ($this->scalar('SELECT COUNT(*) FROM sync_outbox WHERE abandoned_reason IS NULL', []) ?? '0');
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

    /** @param list<string|int|null> $bindings */
    private function run(string $sql, array $bindings): void
    {
        $this->pdo->prepare($sql)->execute($bindings);
    }
}
