<?php

declare(strict_types=1);

namespace Cbox\Sync\Enums;

enum MutationStatus: string
{
    case PreconditionFailed = 'precondition_failed';
    case ValidationFailed = 'validation_failed';
    case Applied = 'applied';
    case Noop = 'noop';
    case Partial = 'partial';
    case Conflict = 'conflict';
    case Rejected = 'rejected';
    case MutationGap = 'mutation_gap';
}
