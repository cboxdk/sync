<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\State;

/**
 * Publishes a fully rehydrated object graph on every commit, the way a durable
 * adapter does. Nothing it hands out after a commit is the instance the caller
 * passed in, so any engine or test that relies on PHP object sharing rather than
 * on value equality fails here instead of during a database integration.
 */
class RehydratingStore extends InMemoryStore
{
    protected function beforeCommit(State $workspace): void
    {
        $rehydrated = unserialize(serialize($workspace));
        if (! $rehydrated instanceof State) {
            throw new \LogicException('Rehydrating the transaction workspace did not produce a state');
        }
        $workspace->records = $rehydrated->records;
        $workspace->groups = $rehydrated->groups;
        $workspace->receipts = $rehydrated->receipts;
        $workspace->acknowledged = $rehydrated->acknowledged;
        $workspace->commits = $rehydrated->commits;
    }
}
