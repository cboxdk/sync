<?php

declare(strict_types=1);

namespace Cbox\Sync\ValueObjects;

use Cbox\Sync\Exceptions\InvalidRequest;

/** Immutable JSON value; missing differs from JSON null. JSON objects are key-order normalized. */
readonly class FieldValue
{
    private function __construct(public bool $exists, private string $json) {}

    public static function missing(): self
    {
        return new self(false, 'null');
    }

    public static function of(mixed $value): self
    {
        try {
            $json = json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            throw new InvalidRequest('Field values must be finite JSON data', previous: $exception);
        }

        return new self(true, $json);
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new InvalidRequest('Field value exceeds depth limit');
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                $object = new \stdClass;
                foreach ($value as $key => $item) {
                    $object->{(string) $key} = $item;
                }

                return self::normalize($object, $depth);
            }
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::normalize($item, $depth + 1);
            }

            return $normalized;
        }
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $normalized = new \stdClass;
            foreach ($fields as $key => $item) {
                $normalized->{$key} = self::normalize($item, $depth + 1);
            }

            return $normalized;
        }
        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidRequest('Only JSON data values are supported');
        }

        return $value;
    }

    /** Canonical JSON encoding, also used by payload identity comparison. */
    public function toJson(): string
    {
        return $this->json;
    }

    public function value(): mixed
    {
        return json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);
    }

    public function equals(self $other): bool
    {
        return $this->exists === $other->exists && $this->json === $other->json;
    }
}
