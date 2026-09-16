<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence\Pdo;

use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\ValueObjects\FieldValue;

/**
 * Encodes the immutable domain objects the adapter stores opaquely.
 *
 * This is PHP's own serialization, not a wire format: it is a storage detail of
 * the reference adapter, and nothing outside the database reads it. A
 * cross-language representation belongs with a transport, which this package
 * does not ship. Everything a query needs is a real column, never in here.
 */
class Payload
{
    public static function encode(object $value): string
    {
        return serialize($value);
    }

    /**
     * @template TExpected of object
     *
     * @param  class-string<TExpected>  $expected
     * @return TExpected
     */
    public static function decode(string $payload, string $expected): object
    {
        $value = unserialize($payload, ['allowed_classes' => true]);
        if (! $value instanceof $expected) {
            throw new ProtocolException('Stored payload is not a '.$expected);
        }

        return $value;
    }

    /** Exact equality for FieldValue, independent of any database's JSON or collation behaviour. */
    public static function fieldHash(FieldValue $value): string
    {
        return hash('sha256', ($value->exists ? '1:' : '0:').$value->toJson());
    }
}
