<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Resolvers\ServerWins;
use Cbox\Sync\Support\UuidV7Generator;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\Replica;

it('rejects spoofed dependencies across replicas entities and spaces', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    expect(fn () => $this->write('b', 1, [Op::set('title', 'B')], dependsOn: 'a-1'))->toThrow(InvalidRequest::class);
    $this->key = new EntityKey('test', 'notes', 'other');
    expect(fn () => $this->write('a', 2, [], 0, dependsOn: 'a-1'))->toThrow(InvalidRequest::class);
    $this->key = new EntityKey('other-space', 'notes', 'one');
    expect(fn () => $this->write('a', 1, [], 0, dependsOn: 'a-1'))->toThrow(InvalidRequest::class);
});

it('carries safe field dependencies through intermediate writes without accepting unrelated remote fields', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('a', 2, [Op::set('body', 'AA')], dependsOn: 'a-1');
    expect($this->write('a', 3, [Op::set('title', 'AAA')], dependsOn: 'a-2')->status)->toBe(MutationStatus::Applied);
    $this->write('b', 1, [Op::set('body', 'B')], 4);
    expect($this->write('a', 4, [Op::set('body', 'AAAA')], dependsOn: 'a-3')->status)->toBe(MutationStatus::Conflict);
});

it('does not grant dependency knowledge after server wins', function () {
    $this->setUpSync(new ServerWins);
    $this->seedRecord();
    $this->write('b', 1, [Op::set('title', 'B')]);
    $first = $this->write('a', 1, [Op::set('title', 'A')]);
    $second = $this->write('a', 2, [Op::set('title', 'AA')], dependsOn: 'a-1');
    expect($first->acceptedVersions)->toBe([]);
    expect($second->decisions['title']->value)->toBe('server_wins');
    expect($this->record()->value('title')->value())->toBe('B');
});

it('deduplicates by mutation and field rather than candidate value', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $mutation = $this->mutation('b', 1, [Op::set('title', 'B')]);
    $this->engine->process($mutation);
    $this->engine->process($mutation);
    $this->write('c', 1, [Op::set('title', 'B')]);
    expect($this->openConflicts()[0]->candidates)->toHaveCount(3);
});

it('keeps an open group with different bases and preserves a new canonical origin', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $this->write('c', 1, [Op::set('title', 'C')], 2);
    $this->write('d', 1, [Op::set('title', 'D')], 2);
    expect($this->openConflicts())->toHaveCount(1);
    expect($this->openConflicts()[0]->candidates)->toHaveCount(4);
    expect(array_unique(array_map(fn ($c) => $c->provenance->baseVersion->value, $this->openConflicts()[0]->candidates)))->toHaveCount(2);
});

it('uses group revision to detect candidates arriving without a record version change', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $group = $this->openConflicts()[0];
    $this->write('c', 1, [Op::set('title', 'C')]);
    $result = $this->engine->process($this->mutation('r', 1, [Op::set('title', 'R')], 2, kind: MutationKind::Resolve, resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates))));
    expect($result->reason)->toBe('stale_resolution');
    expect($this->openConflicts()[0]->resolved)->toBe([]);
});

it('resolves only named candidates without changing equal canonical values', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $group = $this->openConflicts()[0];
    $this->engine->process($this->mutation('r', 1, [Op::set('title', 'A')], 2, kind: MutationKind::Resolve, resolution: new Resolution($group->id, $group->revision, [array_keys($group->candidates)[0]])));
    expect($this->openConflicts()[0]->resolved)->toHaveCount(1);
    expect($this->record()->version->value)->toBe(2);
    expect($this->write('c', 1, [Op::set('title', 'late')], 1)->status)->toBe(MutationStatus::Conflict);
});

it('does not trust from values as evidence', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $result = $this->write('b', 1, [new Op('title', FieldValue::of('B'), FieldValue::of('A'))]);
    expect($result->status)->toBe(MutationStatus::Conflict);
});

it('rejects malformed operation lists and identities before any writes', function () {
    expect(fn () => $this->mutation('a', 1, [Op::set('x', 1), Op::set('x', 2)]))->toThrow(InvalidRequest::class);
    expect(fn () => new MutationSequence(0))->toThrow(InvalidRequest::class);
    expect(fn () => new EntityKey('', 'x', 'y'))->toThrow(InvalidRequest::class);
    expect(fn () => Op::set('', 1))->toThrow(InvalidRequest::class);
    expect(fn () => $this->mutation('a', 1, [Op::set('x', 1)], kind: MutationKind::Delete))->toThrow(InvalidRequest::class);
    expect(fn () => FieldValue::of(INF))->toThrow(InvalidRequest::class);
    expect(fn () => FieldValue::of(new DateTimeImmutable))->toThrow(InvalidRequest::class);
});

it('normalizes JSON object keys but preserves types list order and immutable copies', function () {
    expect(FieldValue::of((object) ['b' => 2, 'a' => 1])->equals(FieldValue::of((object) ['a' => 1, 'b' => 2])))->toBeTrue();
    expect(FieldValue::of(1)->equals(FieldValue::of('1')))->toBeFalse();
    expect(FieldValue::of(1)->equals(FieldValue::of(1.0)))->toBeFalse();
    expect(FieldValue::of([1, 2])->equals(FieldValue::of([2, 1])))->toBeFalse();
    expect(FieldValue::of([])->equals(FieldValue::of((object) [])))->toBeFalse();
});

it('scopes feed and acknowledgements per space and mutation IDs globally', function () {
    $this->seedRecord();
    $this->key = new EntityKey('other', 'notes', 'one');
    $create = $this->mutation('seed', 1, [Op::set('title', 'other')], 0, kind: MutationKind::Create, id: 'other-create');
    expect($this->engine->process($create)->commitSequence->value)->toBe(1);
    expect($this->store->pull('test')->commits)->toHaveCount(1);
    expect($this->store->pull('other')->commits)->toHaveCount(1);
    expect(fn () => $this->engine->process($this->mutation('seed', 2, [], 0, id: 'seed-1')))->toThrow(ProtocolException::class);
    expect(fn () => $this->store->pull('other', 2))->toThrow(InvalidRequest::class);
});

it('generates UUIDv7 identifiers with RFC version variant and timestamp layout', function () {
    $generator = new UuidV7Generator;
    $before = (int) floor(microtime(true) * 1000);
    $ids = [];
    foreach (range(1, 100) as $_) {
        $id = $generator->generate();
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
        $timestamp = hexdec(substr(str_replace('-', '', $id), 0, 12));
        expect($timestamp)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual((int) floor(microtime(true) * 1000));
        $ids[] = $id;
    }
    expect(array_unique($ids))->toHaveCount(100);
});

it('preserves JSON object shape with numeric keys and normalizes string keys lexically', function () {
    $value = FieldValue::of([1 => 'one', 0 => 'zero'])->value();
    expect($value)->toBeInstanceOf(stdClass::class);
    expect($value->{'0'})->toBe('zero');
    $left = new stdClass;
    $left->{'01'} = 'a';
    $left->{'1'} = 'b';
    $right = new stdClass;
    $right->{'1'} = 'b';
    $right->{'01'} = 'a';
    expect(FieldValue::of($left)->equals(FieldValue::of($right)))->toBeTrue();
});

it('recognizes retries reconstructed with equivalent values but different PHP object sharing', function () {
    $value = FieldValue::of('same');
    $original = $this->mutation('a', 1, [new Op('title', $value), new Op('body', $value)], 0, kind: MutationKind::Create);
    $result = $this->engine->process($original);
    $reconstructed = $this->mutation('a', 1, [Op::set('title', 'same'), Op::set('body', 'same')], 0, kind: MutationKind::Create);
    expect($this->engine->process($reconstructed))->toEqual($result);
});

it('classifies malformed candidate IDs as invalid requests before deduplication', function () {
    expect(fn () => new Resolution('group', 1, [new stdClass, new stdClass]))->toThrow(InvalidRequest::class);
    expect(fn () => new Resolution('group', 1, ['a', 'a']))->toThrow(InvalidRequest::class);
    expect(fn () => new Resolution('group', 1, [2 => 'a']))->toThrow(InvalidRequest::class);
});

/** One oversized id used to make every later bootstrap of its view impossible. */
it('refuses an identifier longer than the columns that store it', function () {
    $long = str_repeat('x', 151);

    expect(fn () => new EntityKey('s', 't', $long))->toThrow(InvalidRequest::class, 'longer than 150')
        ->and(fn () => new EntityKey($long, 't', 'i'))->toThrow(InvalidRequest::class)
        ->and(fn () => new Replica($long))->toThrow(InvalidRequest::class)
        ->and(fn () => new EntityKey('s', 't', "a\0b"))->toThrow(InvalidRequest::class, 'NUL')
        // Characters, not bytes: the columns are counted the same way.
        ->and((new EntityKey('s', 't', str_repeat('æ', 150)))->id)->toBe(str_repeat('æ', 150));
});
