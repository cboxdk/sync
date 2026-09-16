<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class MutationSequence
{
    public function __construct(public int $value = 1)
    {
        if ($value < 1) {
            throw new InvalidRequest('MutationSequence below minimum');
        }
    }
}
