<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\PullPage;
use Cbox\Sync\Persistence\State;

interface Store
{
    /** @param \Closure(State): MutationResult $callback */
    public function transaction(\Closure $callback): MutationResult;

    public function snapshot(): State;

    public function pull(string $space, int $after = 0, int $limit = 100): PullPage;
}
