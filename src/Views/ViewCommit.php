<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\ValueObjects\CommitSequence;

readonly class ViewCommit
{
    /** @var list<ViewChange> */
    public array $changes;

    /** @param list<ViewChange> $changes */
    public function __construct(public CommitSequence $sourceSequence, array $changes)
    {
        $copy = [];
        foreach ($changes as $change) {
            $copy[] = $change;
        }
        $this->changes = $copy;
    }
}
