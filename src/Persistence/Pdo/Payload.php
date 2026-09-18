<?php

declare(strict_types=1);

namespace Cbox\Sync\Persistence\Pdo;

use Cbox\Sync\Data\Candidate;
use Cbox\Sync\Data\Change;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldConflict;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\FieldState;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\PreconditionFailure;
use Cbox\Sync\Data\Provenance;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\FieldVersion;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\CursorContext;

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
    /**
     * The storage format this adapter writes.
     *
     * A payload carries it so that changing the encoding later is detectable on
     * read, instead of arriving as a corrupt-looking unserialize failure years
     * after the rows were written. Rows written before the tag existed have no
     * prefix and are read as version 0; base64 cannot contain a colon, so an
     * untagged payload can never be mistaken for a tagged one.
     */
    private const VERSION = 1;

    /**
     * Every class the adapter can legitimately find inside a stored payload.
     *
     * unserialize() allows any class by default, which turns any row an
     * attacker can write - a restored backup, the replica database sitting on
     * an end-user's device, SQL injection anywhere else in the host - into an
     * object-injection gadget chain against whatever classes that application
     * has loaded. Naming the set closes that without trusting the row.
     *
     * The list is derived from what the engine actually stores. It needs no
     * separate completeness test: a class missing from it decodes to an
     * incomplete object, which decode() refuses, so the whole suite run
     * against a PDO store fails the moment something new is stored.
     *
     * @var list<class-string>
     */
    private const ALLOWED = [
        Candidate::class,
        Change::class,
        Commit::class,
        ConflictGroup::class,
        EntityRecord::class,
        FieldConflict::class,
        FieldOperation::class,
        FieldState::class,
        Mutation::class,
        MutationResult::class,
        PreconditionFailure::class,
        Provenance::class,
        Receipt::class,
        Resolution::class,
        ValidationFailure::class,
        ValidationResult::class,
        ChangeKind::class,
        ConflictDecision::class,
        MutationKind::class,
        MutationStatus::class,
        CommitSequence::class,
        EntityKey::class,
        FieldValue::class,
        FieldVersion::class,
        MutationSequence::class,
        RecordVersion::class,
        Replica::class,
        CursorContext::class,
    ];

    public static function encode(object $value): string
    {
        // Base64 because PHP encodes private and protected property names with
        // NUL bytes, which a PostgreSQL text column cannot hold at all. Keeping
        // the payload to a safe alphabet makes it identical on every driver
        // instead of working by accident on the permissive ones.
        return self::VERSION.':'.base64_encode(serialize($value));
    }

    /**
     * @template TExpected of object
     *
     * @param  class-string<TExpected>  $expected
     * @return TExpected
     */
    public static function decode(string $payload, string $expected): object
    {
        [$version, $encoded] = self::split($payload);
        if ($version > self::VERSION) {
            throw new ProtocolException(
                'Stored payload is format version '.$version.', which this adapter cannot read; it reads up to '.self::VERSION
            );
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new ProtocolException('Stored payload is not valid base64');
        }

        $value = unserialize($decoded, ['allowed_classes' => self::ALLOWED]);

        // A class outside the allowed set does not fail - it decodes to an
        // incomplete object, and nested ones would pass the type check below
        // while being unusable. Refusing here keeps that from reaching the
        // engine as a record whose fields quietly no longer work.
        if ($value instanceof \__PHP_Incomplete_Class) {
            throw new ProtocolException('Stored payload names a class this adapter does not store');
        }
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

    /** @return array{int, string} */
    private static function split(string $payload): array
    {
        $colon = strpos($payload, ':');
        if ($colon === false) {
            return [0, $payload];
        }

        $version = substr($payload, 0, $colon);
        if ($version === '' || ctype_digit($version) === false) {
            throw new ProtocolException('Stored payload has a malformed format tag');
        }

        return [(int) $version, substr($payload, $colon + 1)];
    }
}
