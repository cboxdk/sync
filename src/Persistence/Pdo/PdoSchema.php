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

    /**
     * Idempotent DDL, in order. Safe to run repeatedly on every driver.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $statements = [];
        foreach ($this->tables() as $table) {
            $statements[] = $this->createTable($table);
        }
        // MySQL has no CREATE INDEX IF NOT EXISTS, so its indexes are declared
        // inside CREATE TABLE IF NOT EXISTS, which is idempotent as a whole.
        if ($this->driver !== self::MYSQL) {
            foreach ($this->tables() as $table) {
                foreach ($table['indexes'] as $index) {
                    $statements[] = sprintf(
                        'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
                        $index['unique'] ? 'UNIQUE ' : '',
                        $index['name'],
                        $table['name'],
                        implode(', ', $index['columns']),
                    );
                }
            }
        }

        return $statements;
    }

    public function install(\PDO $connection): void
    {
        foreach ($this->statements() as $statement) {
            $connection->exec($statement);
        }
    }

    /**
     * @param  array{name: string, columns: list<string>, primaryKey: list<string>, indexes: list<array{name: string, unique: bool, columns: list<string>}>}  $table
     */
    private function createTable(array $table): string
    {
        $lines = $table['columns'];
        $lines[] = 'PRIMARY KEY ('.implode(', ', $table['primaryKey']).')';
        if ($this->driver === self::MYSQL) {
            foreach ($table['indexes'] as $index) {
                $lines[] = sprintf('%sKEY %s (%s)', $index['unique'] ? 'UNIQUE ' : '', $index['name'], implode(', ', $index['columns']));
            }
        }

        return sprintf("CREATE TABLE IF NOT EXISTS %s (\n    %s\n)", $table['name'], implode(",\n    ", $lines));
    }

    /** @return list<array{name: string, columns: list<string>, primaryKey: list<string>, indexes: list<array{name: string, unique: bool, columns: list<string>}>}> */
    private function tables(): array
    {
        $text = $this->driver === self::MYSQL ? 'LONGTEXT' : 'TEXT';
        $bool = $this->driver === self::PGSQL ? 'BOOLEAN' : 'SMALLINT';
        // Identity columns order bootstrap pages, so they must sort by bytes.
        // MySQL's default collation is case- and accent-insensitive and would
        // page the same data in a different order from every other driver.
        // The length is bounded so the widest composite index stays well inside
        // InnoDB's 3072-byte key limit at four bytes per character.
        $name = match ($this->driver) {
            self::MYSQL => 'VARCHAR(150) COLLATE utf8mb4_bin',
            self::PGSQL => 'TEXT COLLATE "C"',
            default => 'TEXT',
        };

        return [
            [
                'name' => 'sync_spaces',
                'columns' => [
                    "space $name NOT NULL",
                    'commit_sequence BIGINT NOT NULL DEFAULT 0',
                    'retained_from BIGINT NOT NULL DEFAULT 1',
                ],
                'primaryKey' => ['space'],
                'indexes' => [],
            ],
            [
                'name' => 'sync_records',
                'columns' => [
                    "space $name NOT NULL",
                    "entity_type $name NOT NULL",
                    "entity_id $name NOT NULL",
                    'version BIGINT NOT NULL',
                    "deleted $bool NOT NULL",
                    "payload $text NOT NULL",
                ],
                'primaryKey' => ['space', 'entity_type', 'entity_id'],
                'indexes' => [
                    ['name' => 'sync_records_scan', 'unique' => false, 'columns' => ['space', 'deleted', 'entity_type', 'entity_id']],
                ],
            ],
            [
                'name' => 'sync_fields',
                'columns' => [
                    "space $name NOT NULL",
                    "entity_type $name NOT NULL",
                    "entity_id $name NOT NULL",
                    "field $name NOT NULL",
                    'value_hash CHAR(64) NOT NULL',
                ],
                'primaryKey' => ['space', 'entity_type', 'entity_id', 'field'],
                'indexes' => [
                    ['name' => 'sync_fields_lookup', 'unique' => false, 'columns' => ['space', 'field', 'value_hash']],
                ],
            ],
            [
                'name' => 'sync_conflict_groups',
                'columns' => [
                    "id $name NOT NULL",
                    "space $name NOT NULL",
                    "entity_type $name NOT NULL",
                    "entity_id $name NOT NULL",
                    "field $name NOT NULL",
                    'revision BIGINT NOT NULL',
                    "open_key $name NULL",
                    "payload $text NOT NULL",
                ],
                'primaryKey' => ['id'],
                'indexes' => [
                    ['name' => 'sync_conflict_groups_open', 'unique' => true, 'columns' => ['open_key']],
                    ['name' => 'sync_conflict_groups_entity', 'unique' => false, 'columns' => ['space', 'entity_type', 'entity_id', 'field']],
                ],
            ],
            [
                'name' => 'sync_receipts',
                'columns' => [
                    "mutation_id $name NOT NULL",
                    "space $name NOT NULL",
                    "payload $text NOT NULL",
                ],
                'primaryKey' => ['mutation_id'],
                'indexes' => [],
            ],
            [
                'name' => 'sync_streams',
                'columns' => [
                    "space $name NOT NULL",
                    "replica_id $name NOT NULL",
                    'acknowledged BIGINT NOT NULL',
                ],
                'primaryKey' => ['space', 'replica_id'],
                'indexes' => [],
            ],
            [
                'name' => 'sync_commits',
                'columns' => [
                    "space $name NOT NULL",
                    'sequence BIGINT NOT NULL',
                    "payload $text NOT NULL",
                ],
                'primaryKey' => ['space', 'sequence'],
                'indexes' => [],
            ],
        ];
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
