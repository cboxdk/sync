<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\CommitSequence;

readonly class PullPage
{
    /** @var list<Commit> */
    public array $commits;

    /**
     * @param  list<Commit>  $commits
     */
    public function __construct(array $commits = [], public CommitSequence $nextCursor = new CommitSequence, public bool $hasMore = false)
    {
        $commitsCopy = [];
        foreach ($commits as $value) {
            $commitsCopy[] = $value;
        }
        $this->commits = $commitsCopy;
    }
}
