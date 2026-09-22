<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

/** What the suite was pointed at, read one way everywhere. */
final class Environment
{
    /** The variable's value, or the fallback when it is unset or empty. */
    public static function get(string $name, string $fallback = ''): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
