<?php

declare(strict_types=1);

namespace Cbox\Sync\Views;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\FieldValue;

readonly class FieldEqualsView implements ViewDefinition
{
    public function __construct(
        private string $viewId,
        private string $version,
        public string $field,
        public FieldValue $expected,
        public ?string $entityType = null,
    ) {
        if ($viewId === '' || $version === '' || $field === '' || $entityType === '') {
            throw new InvalidRequest('View identity, version, field and optional entity type must not be empty');
        }
    }

    public static function matching(string $viewId, string $version, string $field, mixed $expected, ?string $entityType = null): self
    {
        return new self($viewId, $version, $field, FieldValue::of($expected), $entityType);
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
        return hash('sha256', serialize([
            self::class,
            $this->field,
            $this->expected->exists,
            $this->expected->toJson(),
            $this->entityType,
        ]));
    }

    public function includes(EntityRecord $record): bool
    {
        return ! $record->deleted
            && ($this->entityType === null || $record->entity->type === $this->entityType)
            && $record->value($this->field)->equals($this->expected);
    }
}
