<?php

declare(strict_types=1);

use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Persistence\Pdo\Payload;
use Cbox\Sync\ValueObjects\EntityKey;

it('round-trips a stored object', function () {
    $key = new EntityKey('team-1', 'notes', 'one');

    expect(Payload::decode(Payload::encode($key), EntityKey::class))->toEqual($key);
});

/**
 * Rows written before the format carried a version have no prefix. They must
 * keep decoding: this adapter is released, and a stored row cannot be migrated
 * by a package that does not know where the host put its database.
 */
it('reads a payload written before the format was versioned', function () {
    $key = new EntityKey('team-1', 'notes', 'one');
    $legacy = base64_encode(serialize($key));

    expect($legacy)->not->toContain(':');
    expect(Payload::decode($legacy, EntityKey::class))->toEqual($key);
});

/**
 * The point of the tag: a downgrade says what happened, rather than surfacing
 * as an unserialize failure that reads like a corrupt database.
 */
it('refuses a payload from a newer format and says so', function () {
    $future = '99:'.base64_encode(serialize(new EntityKey('team-1', 'notes', 'one')));

    expect(fn () => Payload::decode($future, EntityKey::class))
        ->toThrow(ProtocolException::class, 'format version 99');
});

it('refuses a malformed format tag', function () {
    expect(fn () => Payload::decode('v1:'.base64_encode(serialize(new EntityKey('a', 'b', 'c'))), EntityKey::class))
        ->toThrow(ProtocolException::class, 'malformed format tag');
});

/**
 * A class outside the allowed set decodes to an incomplete object rather than
 * failing, so refusing it explicitly is what keeps a hostile row from arriving
 * as a record whose fields silently no longer work.
 */
it('refuses a payload naming a class it does not store', function () {
    $foreign = '1:'.base64_encode(serialize(new ArrayObject(['a' => 1])));

    expect(fn () => Payload::decode($foreign, ArrayObject::class))
        ->toThrow(ProtocolException::class, 'does not store');
});

it('refuses a payload that is not valid base64', function () {
    expect(fn () => Payload::decode('1:not base64!!', EntityKey::class))
        ->toThrow(ProtocolException::class, 'not valid base64');
});
