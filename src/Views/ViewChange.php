<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\Provenance;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\RecordVersion;

readonly class ViewChange
{
    public function __construct(
        public int $ordinal,
        public ViewChangeKind $kind,
        public EntityKey $entity,
        public RecordVersion $recordVersion,
        public ?EntityRecord $record = null,
        public ?Provenance $provenance = null,
    ) {
        if ($ordinal < 0) {
            throw new InvalidRequest('View change ordinal must not be negative');
        }
        if (($kind === ViewChangeKind::Upsert) !== ($record !== null)) {
            throw new InvalidRequest('Only an upsert carries a full record');
        }
        if ($record !== null && $record->entity->key() !== $entity->key()) {
            throw new InvalidRequest('View change record identity mismatch');
        }
        if ($record !== null && $record->version->value !== $recordVersion->value) {
            throw new InvalidRequest('View change record version mismatch');
        }
    }
}
