<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

enum ViewChangeKind: string
{
    case Upsert = 'upsert';
    case Deleted = 'deleted';
    case RemovedFromScope = 'removed_from_scope';
}
