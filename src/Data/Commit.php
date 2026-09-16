<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\CommitSequence;

readonly class Commit
{
    /** @var list<Change> */
    public array $changes;

    /**
     * @param  list<Change>  $changes
     */
    public function __construct(public string $space, public CommitSequence $sequence, array $changes)
    {
        $changesCopy = [];
        foreach ($changes as $value) {
            $changesCopy[] = $value;
        }
        $this->changes = $changesCopy;
    }
}
