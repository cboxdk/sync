<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\ValueObjects\CommitSequence;

/**
 * How a paginated bootstrap keeps its place.
 *
 * Two strategies ship, because the guarantee differs and the cost differs with
 * it. See the implementations.
 */
interface BootstrapSessions
{
    public function open(CursorContext $context, ViewDefinition $view, CommitSequence $watermark, int $pageSize): BootstrapToken;

    /** @throws ResetRequired when the token is unknown, expired, or bound to a different view */
    public function page(BootstrapToken $token, ViewDefinition $view): BootstrapPage;
}
