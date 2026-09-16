<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\FieldValue;

readonly class Candidate
{
    public function __construct(public string $id, public string $field, public FieldValue $value, public Provenance $provenance) {}

    public static function fromOperation(Mutation $mutation, FieldOperation $operation, AdapterContext $context = new AdapterContext): self
    {
        return new self(hash('sha256', serialize([$mutation->id, $operation->field])), $operation->field, $operation->value, Provenance::fromMutation($mutation, $context));
    }
}
