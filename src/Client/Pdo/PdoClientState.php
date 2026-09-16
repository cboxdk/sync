<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Persistence\Pdo\Payload;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\Views\CursorContext;
use Cbox\Sync\Views\ViewCursor;

/** Durable client state. SQLite in practice, which is what a packaged desktop or mobile app ships. */
class PdoClientState implements ClientState
{
    private PdoClientSchema $schema;

    private bool $active = false;

    public function __construct(private \PDO $pdo, ?PdoClientSchema $schema = null)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = $schema ?? PdoClientSchema::forConnection($this->pdo);
    }

    public function migrate(): void
    {
        $this->schema->install($this->pdo);
    }

    public function record(EntityKey $entity): ?EntityRecord
    {
        $payload = $this->scalar('SELECT payload FROM sync_client_entities WHERE space = ? AND entity_type = ? AND entity_id = ?', self::key($entity));

        return $payload === null ? null : Payload::decode($payload, EntityRecord::class);
    }

    public function putRecord(EntityRecord $record): void
    {
        $this->upsertEntity($record->entity, ['payload' => Payload::encode($record)]);
    }

    public function forgetRecord(EntityKey $entity): void
    {
        $this->upsertEntity($entity, ['payload' => null]);
    }

    public function version(EntityKey $entity): int
    {
        $value = $this->scalar('SELECT version FROM sync_client_entities WHERE space = ? AND entity_type = ? AND entity_id = ?', self::key($entity));

        return $value === null ? 0 : (int) $value;
    }

    public function setVersion(EntityKey $entity, int $version): void
    {
        $this->upsertEntity($entity, ['version' => $version]);
    }

    public function tombstone(EntityKey $entity): ?int
    {
        $value = $this->scalar('SELECT tombstone FROM sync_client_entities WHERE space = ? AND entity_type = ? AND entity_id = ?', self::key($entity));

        return $value === null ? null : (int) $value;
    }

    public function setTombstone(EntityKey $entity, int $version): void
    {
        $this->upsertEntity($entity, ['tombstone' => $version]);
    }

    public function memberships(EntityKey $entity): array
    {
        return $this->column('SELECT context_key FROM sync_client_memberships WHERE space = ? AND entity_type = ? AND entity_id = ? ORDER BY context_key', self::key($entity));
    }

    public function addMembership(EntityKey $entity, string $contextKey): void
    {
        [$space, $type, $id] = self::key($entity);
        $this->run('DELETE FROM sync_client_memberships WHERE space = ? AND entity_type = ? AND entity_id = ? AND context_key = ?', [$space, $type, $id, $contextKey]);
        $this->run('INSERT INTO sync_client_memberships (space, entity_type, entity_id, context_key) VALUES (?, ?, ?, ?)', [$space, $type, $id, $contextKey]);
    }

    public function removeMembership(EntityKey $entity, string $contextKey): void
    {
        [$space, $type, $id] = self::key($entity);
        $this->run('DELETE FROM sync_client_memberships WHERE space = ? AND entity_type = ? AND entity_id = ? AND context_key = ?', [$space, $type, $id, $contextKey]);
    }

    public function forgetMemberships(EntityKey $entity): void
    {
        $this->run('DELETE FROM sync_client_memberships WHERE space = ? AND entity_type = ? AND entity_id = ?', self::key($entity));
    }

    public function members(string $contextKey): array
    {
        $statement = $this->pdo->prepare('SELECT space, entity_type, entity_id FROM sync_client_memberships WHERE context_key = ? ORDER BY space, entity_type, entity_id');
        $statement->execute([$contextKey]);
        $entities = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (! is_array($row) || ! is_string($row[0] ?? null) || ! is_string($row[1] ?? null) || ! is_string($row[2] ?? null)) {
                throw new \LogicException('Malformed membership row');
            }
            $entities[] = new EntityKey($row[0], $row[1], $row[2]);
        }

        return $entities;
    }

    public function context(string $contextKey): ?CursorContext
    {
        $payload = $this->scalar('SELECT context_payload FROM sync_client_views WHERE context_key = ?', [$contextKey]);

        return $payload === null ? null : Payload::decode($payload, CursorContext::class);
    }

    public function putContext(CursorContext $context): void
    {
        $this->upsertView($context->fingerprint(), $context, []);
    }

    public function cursor(string $contextKey): ?ViewCursor
    {
        $position = $this->scalar('SELECT cursor_position FROM sync_client_views WHERE context_key = ?', [$contextKey]);
        $context = $this->context($contextKey);
        if ($position === null || $context === null) {
            return null;
        }

        return new ViewCursor($context, new CommitSequence((int) $position));
    }

    public function putCursor(ViewCursor $cursor): void
    {
        $this->upsertView($cursor->context->fingerprint(), $cursor->context, ['cursor_position' => $cursor->position->value]);
    }

    public function nextBootstrapToken(string $contextKey): ?string
    {
        return $this->scalar('SELECT next_token FROM sync_client_views WHERE context_key = ?', [$contextKey]);
    }

    public function setNextBootstrapToken(string $contextKey, ?string $token): void
    {
        $this->run('UPDATE sync_client_views SET next_token = ? WHERE context_key = ?', [$token, $contextKey]);
    }

    public function bootstrapTokenApplied(string $contextKey, string $token): bool
    {
        return $this->scalar('SELECT 1 FROM sync_client_applied_tokens WHERE context_key = ? AND token = ?', [$contextKey, $token]) !== null;
    }

    public function markBootstrapTokenApplied(string $contextKey, string $token): void
    {
        $this->run('DELETE FROM sync_client_applied_tokens WHERE context_key = ? AND token = ?', [$contextKey, $token]);
        $this->run('INSERT INTO sync_client_applied_tokens (context_key, token) VALUES (?, ?)', [$contextKey, $token]);
    }

    public function forgetView(string $contextKey): void
    {
        $this->run('DELETE FROM sync_client_views WHERE context_key = ?', [$contextKey]);
        $this->run('DELETE FROM sync_client_applied_tokens WHERE context_key = ?', [$contextKey]);
    }

    public function transaction(\Closure $callback): mixed
    {
        if ($this->active) {
            throw new TransientFailure('Nested client state transaction is unsupported');
        }
        $this->active = true;
        try {
            $this->pdo->exec($this->schema->beginStatement());
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

    /** @param array<string, string|int|null> $columns */
    private function upsertEntity(EntityKey $entity, array $columns): void
    {
        [$space, $type, $id] = self::key($entity);
        if ($this->scalar('SELECT 1 FROM sync_client_entities WHERE space = ? AND entity_type = ? AND entity_id = ?', [$space, $type, $id]) === null) {
            $this->run('INSERT INTO sync_client_entities (space, entity_type, entity_id, version, tombstone, payload) VALUES (?, ?, ?, 0, NULL, NULL)', [$space, $type, $id]);
        }
        foreach ($columns as $column => $value) {
            $this->run('UPDATE sync_client_entities SET '.$column.' = ? WHERE space = ? AND entity_type = ? AND entity_id = ?', [$value, $space, $type, $id]);
        }
    }

    /** @param array<string, string|int|null> $columns */
    private function upsertView(string $contextKey, CursorContext $context, array $columns): void
    {
        if ($this->scalar('SELECT 1 FROM sync_client_views WHERE context_key = ?', [$contextKey]) === null) {
            $this->run('INSERT INTO sync_client_views (context_key, context_payload, cursor_position, next_token) VALUES (?, ?, NULL, NULL)', [$contextKey, Payload::encode($context)]);
        }
        foreach ($columns as $column => $value) {
            $this->run('UPDATE sync_client_views SET '.$column.' = ? WHERE context_key = ?', [$value, $contextKey]);
        }
    }

    /** @return array{string, string, string} */
    private static function key(EntityKey $entity): array
    {
        return [$entity->space, $entity->type, $entity->id];
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
     * @return list<string>
     */
    private function column(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $values = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN, 0) as $value) {
            $values[] = is_string($value) ? $value : throw new \LogicException('Expected a text column');
        }

        return $values;
    }

    /** @param list<string|int|null> $bindings */
    private function run(string $sql, array $bindings): void
    {
        $this->pdo->prepare($sql)->execute($bindings);
    }
}
