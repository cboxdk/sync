<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * A conjunction of exact field equalities, optionally limited to one entity type.
 *
 * A store may use this to narrow which rows it reads. It may never use it to
 * decide membership: the caller always re-applies the view's own predicate,
 * because canonical JSON equality (FieldValue) is stricter than any database's
 * native JSON comparison.
 */
readonly class RecordCriteria
{
    /** @var list<FieldPredicate> */
    public array $predicates;

    /** @param list<FieldPredicate> $predicates */
    public function __construct(public ?string $entityType = null, array $predicates = [])
    {
        if ($entityType === '') {
            throw new InvalidRequest('Criteria entity type must not be empty when present');
        }
        $copy = [];
        foreach ($predicates as $predicate) {
            $copy[] = $predicate;
        }
        $this->predicates = $copy;
    }

    public function matches(EntityRecord $record): bool
    {
        if ($this->entityType !== null && $record->entity->type !== $this->entityType) {
            return false;
        }
        foreach ($this->predicates as $predicate) {
            if (! $predicate->matches($record)) {
                return false;
            }
        }

        return true;
    }
}
