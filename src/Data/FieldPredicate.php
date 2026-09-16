<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\FieldValue;

/** A canonical field value a record must carry. Equality is exact: see FieldValue. */
readonly class FieldPredicate
{
    public function __construct(public string $field, public FieldValue $expected)
    {
        if ($field === '') {
            throw new InvalidRequest('Predicate field must not be empty');
        }
    }

    public function matches(EntityRecord $record): bool
    {
        return $record->value($this->field)->equals($this->expected);
    }
}
