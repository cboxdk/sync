<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\RecordVersion;

readonly class PreconditionFailure
{
    public function __construct(public RecordVersion $expectedVersion, public RecordVersion $actualVersion) {}
}
