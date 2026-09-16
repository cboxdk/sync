<?php

declare(strict_types=1);

namespace Cbox\Sync\Enums;

enum ConflictDecision: string
{
    case Reject = 'reject_on_conflict';
    case Preserve = 'preserve_conflict';
    case Server = 'server_wins';
    case Client = 'client_wins';
}
