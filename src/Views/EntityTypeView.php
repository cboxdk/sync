<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * Every live record of one entity type in the space.
 *
 * The common case, and the one a host should reach for before writing a filter:
 * the space is already the isolation boundary, so a view that narrows no
 * further is not a weaker boundary, only a wider window onto the same tenant.
 *
 * Being queryable is what makes it cheap - the store pages it by entity type
 * through an index, and a delta skips every commit on another type without
 * decoding it.
 */
readonly class EntityTypeView implements QueryableView
{
    public function __construct(private string $viewId, private string $version, public string $entityType)
    {
        if ($viewId === '' || $version === '' || $entityType === '') {
            throw new InvalidRequest('View identity, version and entity type must not be empty');
        }
    }

    /**
     * $version is the filter's label, not the schema's. Change it to force
     * every device to rebuild this view without touching anything else.
     */
    public static function of(string $entityType, string $version = 'v1'): self
    {
        return new self($entityType.'-all', $version, $entityType);
    }

    public function id(): string
    {
        return $this->viewId;
    }

    public function filterVersion(): string
    {
        return $this->version;
    }

    public function filterSignature(): string
    {
        return hash('sha256', serialize([self::class, $this->entityType]));
    }

    public function criteria(): RecordCriteria
    {
        return new RecordCriteria($this->entityType);
    }

    public function includes(EntityRecord $record): bool
    {
        return ! $record->deleted && $record->entity->type === $this->entityType;
    }
}
