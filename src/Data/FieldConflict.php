<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\FieldVersion;
use Cbox\Sync\ValueObjects\RecordVersion;

readonly class FieldConflict
{
    public function __construct(public string $field, public FieldValue $current, public FieldValue $proposed, public FieldVersion $fieldVersion, public RecordVersion $effectiveBase) {}
}
