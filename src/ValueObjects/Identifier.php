<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

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

    public static function check(string $value, string $what): void
    {
        if ($value === '') {
            throw new InvalidRequest($what.' must not be empty');
        }
        // PostgreSQL text cannot hold a NUL at all, so it would store on one
        // driver and fail on another.
        if (str_contains($value, "\0")) {
            throw new InvalidRequest($what.' must not contain a NUL byte');
        }
        if (self::length($value) > self::MAX_LENGTH) {
            throw new InvalidRequest($what.' is longer than '.self::MAX_LENGTH.' characters');
        }
    }

    /**
     * Characters, as the columns count them - without requiring mbstring. A
     * value that is not valid UTF-8 is counted in bytes, which can only make
     * the bound stricter.
     */
    private static function length(string $value): int
    {
        if (strlen($value) <= self::MAX_LENGTH) {
            return strlen($value);
        }
        $characters = preg_match_all('/./su', $value);

        return $characters === false ? strlen($value) : $characters;
    }
}
