<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

/** Invoked inside the storage transaction after merge, before any effects are published. */
readonly class ValidationContext
{
    public function __construct(public ?EntityRecord $previous, public EntityRecord $proposed, public Mutation $mutation, public Provenance $provenance) {}
}
