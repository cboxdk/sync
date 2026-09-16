<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\Data\ConflictContext;
use Cbox\Sync\Enums\ConflictDecision;

interface ConflictResolver
{
    public function resolve(ConflictContext $context): ConflictDecision;
}
