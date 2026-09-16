<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

enum ResetReason: string
{
    case ContextChanged = 'context_changed';
    case CursorAhead = 'cursor_ahead';
    case BootstrapTokenUnknown = 'bootstrap_token_unknown';
    /** The cursor points below the retention horizon; the history it asks for is gone. */
    case HistoryPruned = 'history_pruned';
    /** A legitimate token whose session has aged out, as opposed to one that was never issued. */
    case BootstrapSessionExpired = 'bootstrap_session_expired';
}
