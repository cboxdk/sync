<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\PullPage;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\CommitSequence;

class InMemoryStore implements Store
{
    private State $state;

    private bool $active = false;

    public function __construct()
    {
        $this->state = new State;
    }

    public function transaction(\Closure $callback): MutationResult
    {
        if ($this->active) {
            throw new TransientFailure('Nested or concurrent transaction is unsupported');
        }
        $this->active = true;
        $workspace = clone $this->state;
        try {
            $result = $callback($workspace);
            $this->beforeCommit($workspace);
            $this->state = clone $workspace;

            return $result;
        } finally {
            $this->active = false;
        }
    }

    /** Adapter hook; failure here rolls back even results and acknowledgements. */
    protected function beforeCommit(State $workspace): void {}

    public function snapshot(): State
    {
        return clone $this->state;
    }

    public function pull(string $space, int $after = 0, int $limit = 100): PullPage
    {
        $commits = $this->state->commits[$space] ?? [];
        if ($after < 0 || $after > count($commits) || $limit < 1) {
            throw new InvalidRequest('Invalid pull cursor or limit');
        }
        $page = [];
        $size = 0;
        $cursor = $after;
        foreach ($commits as $commit) {
            if ($commit->sequence->value <= $after) {
                continue;
            }
            if ($page !== [] && $size + count($commit->changes) > $limit) {
                break;
            }
            $page[] = $commit;
            $size += count($commit->changes);
            $cursor = $commit->sequence->value;
        }

        return new PullPage($page, new CommitSequence($cursor), $cursor < count($commits));
    }
}
