<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\FieldVersion;

readonly class FieldState
{
    public function __construct(public FieldValue $value, public FieldVersion $version, public Candidate $origin) {}
}
