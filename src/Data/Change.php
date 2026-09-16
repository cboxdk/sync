<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Enums\ChangeKind;

readonly class Change
{
    public function __construct(public int $ordinal, public ChangeKind $kind, public ?EntityRecord $record = null, public ?ConflictGroup $group = null, public ?Receipt $receipt = null, public ?EntityRecord $previousRecord = null, public ?Provenance $provenance = null) {}

    /** Notification eligibility only; mutation/conflict metadata still travels in the log. */
    public function isDataChange(): bool
    {
        return $this->kind === ChangeKind::Record || $this->kind === ChangeKind::Deleted;
    }
}
