<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Persistence\InMemoryStore;

/** Fault injection at the final storage boundary, after all effects have been staged. */
class FailingStore extends InMemoryStore
{
    use InjectsCommitFailure;
}
