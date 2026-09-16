<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * Local tables for one device.
 *
 * Canonical knowledge - the highest version seen and the delete watermark -
 * lives in its own table rather than on the record, because it has to survive
 * the record being dropped. That watermark is the only thing stopping a slow
 * view from resurrecting something this client already saw deleted.
 */
class PdoClientSchema
{
    public function __construct(public readonly string $driver)
    {
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new InvalidRequest('Unsupported PDO driver: '.$driver);
        }
    }

    public static function forConnection(\PDO $connection): self
    {
        $driver = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return new self(is_string($driver) ? $driver : '');
    }

    /** @return list<string> */
    public function statements(): array
    {
        $text = $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $name = match ($this->driver) {
            'mysql' => 'VARCHAR(150) COLLATE utf8mb4_bin',
            'pgsql' => 'TEXT COLLATE "C"',
            default => 'TEXT',
        };
        $key = $this->driver === 'mysql' ? 'VARCHAR(64) COLLATE utf8mb4_bin' : 'TEXT';

        $statements = [
            "CREATE TABLE IF NOT EXISTS sync_client_entities (
                space $name NOT NULL,
                entity_type $name NOT NULL,
                entity_id $name NOT NULL,
                version BIGINT NOT NULL DEFAULT 0,
                tombstone BIGINT NULL,
                payload $text NULL,
                PRIMARY KEY (space, entity_type, entity_id)
            )",
            "CREATE TABLE IF NOT EXISTS sync_client_memberships (
                space $name NOT NULL,
                entity_type $name NOT NULL,
                entity_id $name NOT NULL,
                context_key $key NOT NULL,
                PRIMARY KEY (space, entity_type, entity_id, context_key)
            )",
            "CREATE TABLE IF NOT EXISTS sync_client_views (
                context_key $key NOT NULL,
                context_payload $text NOT NULL,
                cursor_position BIGINT NULL,
                next_token $text NULL,
                PRIMARY KEY (context_key)
            )",
            "CREATE TABLE IF NOT EXISTS sync_client_applied_tokens (
                context_key $key NOT NULL,
                token $key NOT NULL,
                PRIMARY KEY (context_key, token)
            )",
        ];
        if ($this->driver === 'mysql') {
            $statements[1] = str_replace(
                'PRIMARY KEY (space, entity_type, entity_id, context_key)',
                "PRIMARY KEY (space, entity_type, entity_id, context_key),\n                KEY sync_client_memberships_context (context_key)",
                $statements[1],
            );

            return $statements;
        }
        $statements[] = 'CREATE INDEX IF NOT EXISTS sync_client_memberships_context ON sync_client_memberships (context_key)';

        return $statements;
    }

    public function install(\PDO $connection): void
    {
        foreach ($this->statements() as $statement) {
            $connection->exec($statement);
        }
    }

    public function beginStatement(): string
    {
        return $this->driver === 'sqlite' ? 'BEGIN IMMEDIATE' : 'BEGIN';
    }
}
