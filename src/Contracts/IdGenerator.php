<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

interface IdGenerator
{
    public function generate(): string;
}
