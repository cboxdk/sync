<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Persistence\Pdo\Payload;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

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

/**
 * The log is the only thing here that grows without bound, and a one-field
 * edit used to store ~22KB of commit. Asserted as a size a real write
 * produces, not as "it is compressed", so a change that quietly stores the
 * record twice again fails here.
 */
it('stores a one-field edit on a ten-field record in a few kilobytes', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    $store->migrate();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'tasks', 'one');
    $fields = [];
    for ($i = 0; $i < 10; $i++) {
        $fields[] = FieldOperation::set('field'.$i, str_repeat('x', 40));
    }
    $engine->process(new Mutation('c', $key, new Replica('r'), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), $fields));
    $engine->process(new Mutation('u', $key, new Replica('r'), new MutationSequence(2), MutationKind::Update, new RecordVersion(1), [FieldOperation::set('field3', 'edited')]));

    $commit = $pdo->query('SELECT LENGTH(payload) FROM sync_commits WHERE sequence = 2')?->fetchColumn();

    expect((int) $commit)->toBeLessThan(4096);
    expect($store->pull('team', 1)->commits[0]->changes[0]->record?->value('field3')->value())->toBe('edited');
});

/** Rows written by earlier releases stay readable after an upgrade. */
it('still reads the formats earlier releases wrote', function () {
    $key = new EntityKey('team-1', 'notes', 'one');

    expect(Payload::decode(base64_encode(serialize($key)), EntityKey::class))->toEqual($key)
        ->and(Payload::decode('1:'.base64_encode(serialize($key)), EntityKey::class))->toEqual($key)
        ->and(Payload::decode(Payload::encode($key), EntityKey::class))->toEqual($key)
        ->and(Payload::encode($key))->toStartWith('2:');
});

/** A small row can inflate to gigabytes; it is refused rather than attempted. */
it('refuses a payload that inflates past its bound', function () {
    // Built in chunks, so the test itself never holds the expanded bytes.
    $stream = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
    expect($stream)->not->toBeFalse();
    $chunk = str_repeat('a', 1024 * 1024);
    $deflated = '';
    for ($i = 0; $i < 17; $i++) {
        $deflated .= (string) deflate_add($stream, $chunk, ZLIB_NO_FLUSH);
    }
    $deflated .= (string) deflate_add($stream, '', ZLIB_FINISH);
    $bomb = '2:'.base64_encode($deflated);

    expect(fn () => Payload::decode($bomb, EntityKey::class))
        ->toThrow(ProtocolException::class, 'inflates past');
});

it('refuses a payload that is not valid deflate data', function () {
    expect(fn () => Payload::decode('2:'.base64_encode('not deflate at all'), EntityKey::class))
        ->toThrow(ProtocolException::class, 'not valid deflate');
});

/** A stream cut short inflates to a prefix without complaint; only its end marker says it is whole. */
it('refuses a payload whose deflate stream was cut short', function () {
    $whole = (string) gzdeflate(serialize(new EntityKey('team-1', 'notes', str_repeat('x', 100))), 3);

    expect(fn () => Payload::decode('2:'.base64_encode(substr($whole, 0, -4)), EntityKey::class))
        ->toThrow(ProtocolException::class);
});
