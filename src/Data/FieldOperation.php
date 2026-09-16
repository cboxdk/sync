<?php

declare(strict_types=1);

namespace Cbox\Sync\Data;

use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\ValueObjects\FieldValue;

readonly class FieldOperation
{
    public function __construct(public string $field, public FieldValue $value, public ?FieldValue $from = null)
    {
        if ($field === '') {
            throw new InvalidRequest('Field name must not be empty');
        }
    }

    public static function set(string $field, mixed $value): self
    {
        return new self($field, FieldValue::of($value));
    }

    public static function unset(string $field): self
    {
        return new self($field, FieldValue::missing());
    }
}
