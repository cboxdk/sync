<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Contracts\IdGenerator;

class FakeIdGenerator implements IdGenerator
{
    private int $sequence = 0;

    public function generate(): string
    {
        return 'generated-'.++$this->sequence;
    }
}
