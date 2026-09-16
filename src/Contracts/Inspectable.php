<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\Persistence\State;

/**
 * Whole-store access for tests and diagnostics. Deliberately not part of Store:
 * a durable adapter cannot materialize its whole state, and no production path
 * may depend on it.
 */
interface Inspectable
{
    public function snapshot(): State;
}
