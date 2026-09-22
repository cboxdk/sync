<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence;

use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\Receipt;

/**
 * Whole-store contents behind the in-memory adapter. All stored domain objects
 * are immutable; copying arrays isolates a transaction.
 *
 * @internal Reachable only through Contracts\Inspectable, for tests and diagnostics.
 */
class State
{
    /** @var array<string, EntityRecord> */
    public array $records = [];

    /** @var array<string, ConflictGroup> */
    public array $groups = [];

    /** @var array<string, Receipt> Global mutation identities */
    public array $receipts = [];

    /** @var array<string, int> */
    public array $acknowledged = [];

    /** @var array<string, int> stream => acknowledged when its receipts were last pruned */
    public array $prunedThrough = [];

    /** @var array<string, list<Commit>> */
    public array $commits = [];

    /** @var array<string, int> Lowest retained sequence per space, once history has been pruned */
    public array $retainedFrom = [];

    /**
     * Highest sequence ever assigned per space.
     *
     * Kept separately from the commits themselves: derived from retained
     * history it would rewind when history is pruned, and the next mutation
     * would reuse a number a client had already consumed.
     *
     * @var array<string, int>
     */
    public array $watermark = [];
}
