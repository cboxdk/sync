<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Pdo;

/**
 * Retypes identity columns an earlier release created as utf8mb4_bin.
 *
 * That collation pads with spaces, so "a" and "a " are one key. The server now
 * keeps them apart, and a client database that did not would merge two records
 * the server holds separately - their values, versions and tombstones. Only
 * VARCHAR columns carrying exactly that collation are touched, one ALTER per
 * table, so it runs once and is a no-op afterwards.
 */
final class MysqlCollation
{
    public static function repair(\PDO $pdo, string $table): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $statement = $pdo->prepare(
            "SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE = 'varchar' AND COLLATION_NAME = 'utf8mb4_bin'"
        );
        $statement->execute([$table]);
        $modify = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (! is_array($row) || ! is_string($row[0] ?? null) || ! is_numeric($row[1] ?? null)) {
                continue;
            }
            $modify[] = sprintf('MODIFY %s VARCHAR(%d) COLLATE utf8mb4_0900_bin %s', $row[0], (int) $row[1], ($row[2] ?? 'NO') === 'YES' ? 'NULL' : 'NOT NULL');
        }
        if ($modify !== []) {
            $pdo->exec(sprintf('ALTER TABLE %s %s', $table, implode(', ', $modify)));
        }
    }
}
