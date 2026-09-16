<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

readonly class Provenance
{
    public function __construct(public string $mutationId, public Replica $replica, public MutationSequence $sequence, public RecordVersion $baseVersion, public ?string $actorId = null, public ?string $integrationId = null) {}

    public static function fromMutation(Mutation $mutation, AdapterContext $context = new AdapterContext): self
    {
        return new self($mutation->id, $mutation->replica, $mutation->sequence, $mutation->baseVersion, $context->actorId, $context->integrationId);
    }
}
