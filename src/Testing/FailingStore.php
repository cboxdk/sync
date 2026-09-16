<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\State;

/** Fault injection at the final storage boundary, after all effects have been staged. */
class FailingStore extends InMemoryStore
{
    private bool $fail = false;

    public function failNextCommit(): void
    {
        $this->fail = true;
    }

    protected function beforeCommit(State $workspace): void
    {
        if ($this->fail) {
            $this->fail = false;
            throw new TransientFailure('Injected storage failure');
        }
    }
}
