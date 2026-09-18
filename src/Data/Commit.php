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

    /**
     * The entity type every change in this commit belongs to.
     *
     * A commit is one mutation on one entity, so the type belongs to the whole
     * commit rather than to a change. A store may record it as a column and use
     * it to skip a commit that a view cannot possibly match, without paying to
     * decode the payload first.
     *
     * Null means the type could not be read from this commit, not that it
     * matches nothing: a caller narrowing on it must still deliver the commit.
     */
    public function entityType(): ?string
    {
        foreach ($this->changes as $change) {
            $entity = $change->record->entity
                ?? $change->previousRecord->entity
                ?? $change->group?->entity;
            if ($entity !== null) {
                return $entity->type;
            }
        }

        return null;
    }
}
