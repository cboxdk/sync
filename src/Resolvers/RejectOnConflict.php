<?php

declare(strict_types=1);

namespace Cbox\Sync\Resolvers;

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Data\ConflictContext;
use Cbox\Sync\Enums\ConflictDecision;

class RejectOnConflict implements ConflictResolver
{
    public function resolve(ConflictContext $context): ConflictDecision
    {
        return ConflictDecision::Reject;
    }
}
