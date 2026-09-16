<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

class ResetRequired extends \RuntimeException
{
    public function __construct(public readonly ResetReason $reason)
    {
        parent::__construct('View sync reset required: '.$reason->value);
    }
}
