<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Persistence\Pdo\PdoStore;

/** The durable adapter, with the same injected failure the in-memory one takes. */
class FailingPdoStore extends PdoStore
{
    use InjectsCommitFailure;
}
