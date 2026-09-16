<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

readonly class ConflictContext
{
    public function __construct(public EntityRecord $record, public FieldOperation $operation, public Mutation $mutation) {}
}
