<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class BootstrapToken
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new InvalidRequest('Bootstrap token must not be empty');
        }
    }
}
