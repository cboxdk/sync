<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Exceptions\TransientFailure;

/**
 * Fails the next commit at the final storage boundary, after every effect has
 * been staged.
 *
 * A trait rather than a base class so the same injection works on both
 * adapters: the property it proves - that a failure here takes back the domain
 * state, the conflicts, the receipt, the acknowledgement and the commit
 * together - is the one worth checking against a real database, not only
 * against an array.
 */
trait InjectsCommitFailure
{
    private bool $failNextCommit = false;

    public function failNextCommit(): void
    {
        $this->failNextCommit = true;
    }

    protected function beforeCommit(string $space): void
    {
        if ($this->failNextCommit) {
            $this->failNextCommit = false;
            throw new TransientFailure('Injected storage failure');
        }
    }
}
