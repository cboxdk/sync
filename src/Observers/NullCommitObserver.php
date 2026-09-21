<?php

declare(strict_types=1);

namespace Cbox\Sync\Observers;

use Cbox\Sync\Contracts\CommitObserver;
use Cbox\Sync\ValueObjects\CommitSequence;

/** Nobody is listening, which is the default: an engine owes no one a signal. */
class NullCommitObserver implements CommitObserver
{
    public function committed(string $space, CommitSequence $watermark): void {}
}
