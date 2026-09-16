<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\RecordVersion;

readonly class EntityRecord
{
    /** @var array<string, FieldState> */
    public array $fields;

    /**
     * @param  array<string, FieldState>  $fields
     */
    public function __construct(public EntityKey $entity, public RecordVersion $version, array $fields = [], public bool $deleted = false, public ?Provenance $deletion = null)
    {
        $fieldsCopy = [];
        foreach ($fields as $key => $value) {
            $fieldsCopy[$key] = $value;
        }
        $this->fields = $fieldsCopy;
    }

    public function value(string $field): FieldValue
    {
        return ($this->fields[$field] ?? null)->value ?? FieldValue::missing();
    }
}
