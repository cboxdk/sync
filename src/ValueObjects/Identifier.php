<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * The bound every stored identifier shares.
 *
 * Identity columns are VARCHAR(150) on MySQL, which is what keeps the widest
 * composite index inside InnoDB's key limit. Unbounded, one oversized id is
 * worse than a failed insert: a keyset bootstrap token embeds the last id of a
 * page, so a 200,000-character id produced a continuation token too large to
 * post back, and every device bootstrapping that view was stuck for good.
 * Checking here, where every entry point constructs one, means no path can
 * skip it.
 */
final class Identifier
{
    public const MAX_LENGTH = 150;

    /** Every identifier a new write carries, bounded at the point it is made. */
    public static function checkMutation(Mutation $mutation): void
    {
        self::check($mutation->id, 'Mutation id');
        if ($mutation->dependsOn !== null) {
            self::check($mutation->dependsOn, 'Mutation dependency');
        }
        self::check($mutation->entity->space, 'Space');
        self::check($mutation->entity->type, 'Entity type');
        self::check($mutation->entity->id, 'Entity id');
        self::check($mutation->replica->id, 'Replica identity');
    }

    public static function check(string $value, string $what): void
    {
        if ($value === '') {
            throw new InvalidRequest($what.' must not be empty');
        }
        // Valid UTF-8 and no NUL, because MySQL and PostgreSQL refuse either
        // with a driver error that SQLite would have stored.
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidRequest($what.' must be valid UTF-8');
        }
        if (str_contains($value, "\0")) {
            throw new InvalidRequest($what.' must not contain a NUL byte');
        }
        if (self::length($value) > self::MAX_LENGTH) {
            throw new InvalidRequest($what.' is longer than '.self::MAX_LENGTH.' characters');
        }
    }

    /** Characters, as the columns count them - without requiring mbstring. */
    private static function length(string $value): int
    {
        if (strlen($value) <= self::MAX_LENGTH) {
            return strlen($value);
        }

        return (int) preg_match_all('/./su', $value);
    }
}
