<?php

declare(strict_types=1);

namespace Cbox\Sync\Enums;

enum MutationKind: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Resolve = 'resolve';
}
