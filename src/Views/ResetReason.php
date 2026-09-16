<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

enum ResetReason: string
{
    case ContextChanged = 'context_changed';
    case CursorAhead = 'cursor_ahead';
    case BootstrapTokenUnknown = 'bootstrap_token_unknown';
}
