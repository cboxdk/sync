<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence\Pdo;

use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * The tables the PDO adapter needs, for SQLite, MySQL 8+ and PostgreSQL.
 *
 * Only what is queried gets a column. Immutable domain objects are stored as
 * opaque payloads, so the schema never has to mirror every DTO; the one
 * exception is sync_fields, which exists purely so a view's field equality can
 * be an index lookup instead of a scan. It stores a hash rather than the value,
 * which keeps equality exact without depending on any database's JSON handling
 * or string collation.
 */
class PdoSchema
{
    public const SQLITE = 'sqlite';

    public const MYSQL = 'mysql';

    public const PGSQL = 'pgsql';

    public function __construct(public readonly string $driver)
    {
        if (! in_array($driver, [self::SQLITE, self::MYSQL, self::PGSQL], true)) {
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
        $text = $this->driver === self::MYSQL ? 'LONGTEXT' : 'TEXT';
        $bool = $this->driver === self::PGSQL ? 'BOOLEAN' : 'SMALLINT';
        // Identity columns order bootstrap pages, so they must sort by bytes.
        // MySQL's default collation is case- and accent-insensitive and would
        // page the same data in a different order from every other driver.
        $name = match ($this->driver) {
            self::MYSQL => 'VARCHAR(191) COLLATE utf8mb4_bin',
            self::PGSQL => 'TEXT COLLATE "C"',
            default => 'TEXT',
        };

        return [
            "CREATE TABLE IF NOT EXISTS sync_spaces (
                space $name NOT NULL,
                commit_sequence BIGINT NOT NULL DEFAULT 0,
                retained_from BIGINT NOT NULL DEFAULT 1,
                PRIMARY KEY (space)
            )",
            "CREATE TABLE IF NOT EXISTS sync_records (
                space $name NOT NULL,
                entity_type $name NOT NULL,
                entity_id $name NOT NULL,
                version BIGINT NOT NULL,
                deleted $bool NOT NULL,
                payload $text NOT NULL,
                PRIMARY KEY (space, entity_type, entity_id)
            )",
            'CREATE INDEX IF NOT EXISTS sync_records_scan ON sync_records (space, deleted, entity_type, entity_id)',
            "CREATE TABLE IF NOT EXISTS sync_fields (
                space $name NOT NULL,
                entity_type $name NOT NULL,
                entity_id $name NOT NULL,
                field $name NOT NULL,
                value_hash CHAR(64) NOT NULL,
                PRIMARY KEY (space, entity_type, entity_id, field)
            )",
            'CREATE INDEX IF NOT EXISTS sync_fields_lookup ON sync_fields (space, field, value_hash)',
            "CREATE TABLE IF NOT EXISTS sync_conflict_groups (
                id $name NOT NULL,
                space $name NOT NULL,
                entity_type $name NOT NULL,
                entity_id $name NOT NULL,
                field $name NOT NULL,
                revision BIGINT NOT NULL,
                open_key $name NULL,
                payload $text NOT NULL,
                PRIMARY KEY (id)
            )",
            'CREATE UNIQUE INDEX IF NOT EXISTS sync_conflict_groups_open ON sync_conflict_groups (open_key)',
            'CREATE INDEX IF NOT EXISTS sync_conflict_groups_entity ON sync_conflict_groups (space, entity_type, entity_id, field)',
            "CREATE TABLE IF NOT EXISTS sync_receipts (
                mutation_id $name NOT NULL,
                space $name NOT NULL,
                payload $text NOT NULL,
                PRIMARY KEY (mutation_id)
            )",
            "CREATE TABLE IF NOT EXISTS sync_streams (
                space $name NOT NULL,
                replica_id $name NOT NULL,
                acknowledged BIGINT NOT NULL,
                PRIMARY KEY (space, replica_id)
            )",
            "CREATE TABLE IF NOT EXISTS sync_commits (
                space $name NOT NULL,
                sequence BIGINT NOT NULL,
                payload $text NOT NULL,
                PRIMARY KEY (space, sequence)
            )",
        ];
    }

    public function install(\PDO $connection): void
    {
        foreach ($this->statements() as $statement) {
            $connection->exec($statement);
        }
    }

    /** MySQL has no partial unique index, so the open marker is a nullable column: NULLs do not collide. */
    public function openKey(string $space, string $type, string $id, string $field): string
    {
        return hash('sha256', serialize([$space, $type, $id, $field]));
    }

    public function locksRows(): bool
    {
        return $this->driver !== self::SQLITE;
    }

    public function beginStatement(): string
    {
        // SQLite takes its write lock lazily, which would let two readers race
        // to the same commit sequence before either writes.
        return $this->driver === self::SQLITE ? 'BEGIN IMMEDIATE' : 'BEGIN';
    }
}
