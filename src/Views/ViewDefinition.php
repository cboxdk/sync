<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;

interface ViewDefinition
{
    public function id(): string;

    public function filterVersion(): string;

    /** A stable signature of the actual filter inputs, not only their version label. */
    public function filterSignature(): string;

    public function includes(EntityRecord $record): bool;
}
