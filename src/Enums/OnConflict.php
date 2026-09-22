<?php

declare(strict_types=1);

namespace Cbox\Sync\Enums;

/**
 * What a writer wants to happen when its edit meets a newer one.
 *
 * Resolve is the server deciding, through the configured resolver, with the
 * writer told afterwards. Pull is the writer asking to decide itself: a field
 * the resolver would have preserved as a conflict is refused instead, nothing
 * is stored, and the writer is told to catch up and send the edit again against
 * what it now knows.
 *
 * Pull never overrides the resolver. A field the host resolves as client-wins
 * or server-wins is settled exactly as before; only the case that would have
 * needed a person to choose is handed back to the device that can ask one.
 */
enum OnConflict: string
{
    case Resolve = 'resolve';
    case Pull = 'pull';
}
