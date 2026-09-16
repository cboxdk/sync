<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

use Cbox\Sync\Exceptions\InvalidRequest;

readonly class Replica
{
    public function __construct(public string $id)
    {
        if ($id === '') {
            throw new InvalidRequest('Replica identity must not be empty');
        }
    }

    public function stream(string $space): string
    {
        return serialize([$space, $this->id]);
    }
}
