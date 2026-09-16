<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class RecordVersion
{
    public function __construct(public int $value = 0)
    {
        if ($value < 0) {
            throw new InvalidRequest('RecordVersion below minimum');
        }
    }
}
