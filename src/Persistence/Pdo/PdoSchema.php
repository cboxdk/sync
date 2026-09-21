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

        return array_merge($statements, $this->indexStatements());
    }

    /**
     * MySQL has no CREATE INDEX IF NOT EXISTS, so its indexes are declared
     * inside CREATE TABLE IF NOT EXISTS, which is idempotent as a whole.
     *
     * @return list<string>
     */
    private function indexStatements(): array
    {
        if ($this->driver === self::MYSQL) {
            return [];
        }

        $statements = [];
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

        return $statements;
    }

    public function install(\PDO $connection): void
    {
        foreach ($this->tables() as $table) {
            $connection->exec($this->createTable($table));
        }

        // Between the tables and the indexes, because an index over a column
        // this installation predates cannot be created before the column is.
        //
        // CREATE TABLE IF NOT EXISTS does nothing to a table that already
        // exists, so without this an older installation would keep running
        // against a schema the adapter can no longer write to. Reconciling here
        // means a host upgrading the package needs no migration of its own,
        // including one using the bare PDO adapter with no framework at all.
        $this->addMissingColumns($connection);

        foreach ($this->indexStatements() as $statement) {
            $connection->exec($statement);
        }
        $this->addMissingIndexes($connection);
    }

    private function addMissingColumns(\PDO $connection): void
    {
        foreach ($this->tables() as $table) {
            $existing = $this->columnNames($connection, $table['name']);
            foreach ($table['columns'] as $definition) {
                $column = (string) strtok($definition, ' ');
                if ($column === '' || in_array(strtolower($column), $existing, true)) {
                    continue;
                }
                // A NOT NULL column with no default cannot be added to a table
                // holding rows. Refusing by name beats a driver error that does
                // not say which column or why.
                if (stripos($definition, ' NOT NULL') !== false && stripos($definition, ' DEFAULT ') === false) {
                    throw new InvalidRequest(sprintf(
                        'Table %s is missing column %s, which is NOT NULL with no default; adding it to a table that already holds rows needs a migration that backfills it.',
                        $table['name'],
                        $column,
                    ));
                }
                $connection->exec(sprintf('ALTER TABLE %s ADD COLUMN %s', $table['name'], $definition));
            }
        }
    }

    private function addMissingIndexes(\PDO $connection): void
    {
        // Everyone else got CREATE INDEX IF NOT EXISTS in statements(). MySQL
        // declares its indexes inside CREATE TABLE, which is exactly the part
        // an existing table skips.
        if ($this->driver !== self::MYSQL) {
            return;
        }

        foreach ($this->tables() as $table) {
            foreach ($table['indexes'] as $index) {
                $lookup = $connection->prepare(
                    'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
                );
                $lookup->execute([$table['name'], $index['name']]);
                if ($lookup->fetchColumn() !== false) {
                    continue;
                }
                $connection->exec(sprintf(
                    'ALTER TABLE %s ADD %sINDEX %s (%s)',
                    $table['name'],
                    $index['unique'] ? 'UNIQUE ' : '',
                    $index['name'],
                    implode(', ', $index['columns']),
                ));
            }
        }
    }

    /** @return list<string> Lowercased, because drivers disagree on the case they report. */
    private function columnNames(\PDO $connection, string $table): array
    {
        [$sql, $bindings] = match ($this->driver) {
            self::SQLITE => ['SELECT name FROM pragma_table_info(?)', [$table]],
            self::MYSQL => ['SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]],
            default => ['SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]],
        };

        $statement = $connection->prepare($sql);
        $statement->execute($bindings);

        $names = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $names[] = strtolower($name);
            }
        }

        return $names;
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
                    // Carries the keyset columns so a view's predicate can both
                    // MATCH and ORDER from one index: without them the planner
                    // drives from sync_records and probes this table once per
                    // record in the space, which makes a selective bootstrap
                    // page cost O(space) instead of O(page).
                    //
                    // Named apart from the sync_fields_lookup it replaces, so an
                    // existing installation gains it by reconciliation rather
                    // than by dropping and rebuilding an index on a live table
                    // during a migration. The old one is a strict prefix of this
                    // one and can be dropped whenever the host chooses.
                    ['name' => 'sync_fields_view', 'unique' => false, 'columns' => ['space', 'field', 'value_hash', 'entity_type', 'entity_id']],
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
                    // Nullable on purpose: rows written before this column
                    // existed carry NULL, and a reader narrowing by type has to
                    // treat that as "unknown" and still deliver them. Backfilling
                    // would mean decoding every commit a host has ever stored.
                    "entity_type $name NULL",
                    "payload $text NOT NULL",
                ],
                'primaryKey' => ['space', 'sequence'],
                'indexes' => [
                    ['name' => 'sync_commits_type', 'unique' => false, 'columns' => ['space', 'entity_type', 'sequence']],
                ],
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
