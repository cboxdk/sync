<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

/** @internal */
readonly class BootstrapRequest
{
    public function __construct(public string $sessionId, public int $offset) {}
}
