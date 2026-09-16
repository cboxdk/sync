<?php

declare(strict_types=1);

namespace Cbox\Sync\Enums;

enum ChangeKind: string
{
    case Deleted = 'deleted';
    case Record = 'record';
    case Conflict = 'conflict';
    case Mutation = 'mutation';
}
