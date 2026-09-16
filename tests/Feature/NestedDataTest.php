<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\InvalidRequest;

/**
 * A field holds arbitrary JSON and the engine treats the whole value as one
 * unit. Nothing merges inside a field. These pin what that actually means,
 * because "it merges independent edits" invites exactly the wrong assumption
 * about a nested document.
 */
function doc(mixed $value): array
{
    return [Op::set('doc', $value)];
}

it('does not merge inside a field, and keeps the losing branch intact', function () {
    $this->seedRecord();
    $this->write('a', 1, doc((object) ['title' => 'draft', 'meta' => (object) ['tags' => ['x'], 'author' => 'ada']]), 1);

    $base = $this->record()->version->value;

    // Two devices change DIFFERENT branches of the same document. Semantically
    // independent; to the engine it is one field, so it is a conflict.
    $alice = $this->write('alice', 1, doc((object) ['title' => 'alice title', 'meta' => (object) ['tags' => ['x'], 'author' => 'ada']]), $base);
    expect($alice->status)->toBe(MutationStatus::Applied);

    $bob = $this->write('bob', 1, doc((object) ['title' => 'draft', 'meta' => (object) ['tags' => ['x', 'y'], 'author' => 'ada']]), $base);
    expect($bob->status)->toBe(MutationStatus::Conflict);

    // Bob's whole document survives as a candidate: nothing about his branch is
    // lost, but nothing merges it either. Reconciling is the application's job.
    $group = $this->openConflicts()[0];
    $values = [];
    foreach ($group->candidates as $candidate) {
        $values[] = $candidate->value->value()->meta->tags;
    }
    expect($values)->toContain(['x', 'y']);
    expect($values)->toContain(['x']);
});

it('treats a reordered object as the same value and does not bump the version', function () {
    $this->seedRecord();
    $this->write('a', 1, doc((object) ['b' => 1, 'a' => (object) ['d' => 4, 'c' => 3]]), 1);
    $version = $this->record()->version->value;

    // Same document, different key order at two levels.
    $result = $this->write('a', 2, doc((object) ['a' => (object) ['c' => 3, 'd' => 4], 'b' => 1]), $version);

    expect($result->status)->toBe(MutationStatus::Noop);
    expect($this->record()->version->value)->toBe($version);
});

it('keeps an empty object and an empty array apart at depth', function () {
    $this->seedRecord();
    $this->write('a', 1, doc((object) ['items' => new stdClass]), 1);
    $version = $this->record()->version->value;

    $changed = $this->write('a', 2, doc((object) ['items' => []]), $version);

    expect($changed->status)->toBe(MutationStatus::Applied);
    expect($this->record()->version->value)->toBe($version + 1);
});

it('conflicts per field, not per record, so an untouched field still accepts a stale write', function () {
    $this->seedRecord();
    $base = $this->record()->version->value;

    // Device a moves `doc` only. The record version advances; `title` does not.
    $this->write('a', 1, doc((object) ['n' => 1]), $base);
    $afterFirst = $this->record()->version->value;
    expect($afterFirst)->toBe($base + 1);

    // Device b is still on the old base and writes `title`. The record has moved
    // underneath it, but the field it names has not, so this is not a conflict.
    // Record-level conflict detection would lose this write.
    $result = $this->write('b', 1, [Op::set('title', 'from b')], $base);

    expect($result->status)->toBe(MutationStatus::Applied);
    expect($this->record()->value('doc')->value()->n)->toBe(1);
    expect($this->record()->value('title')->value())->toBe('from b');
    expect($this->record()->version->value)->toBe($afterFirst + 1);
});

it('resolves a nested conflict to a document the application merged itself', function () {
    $this->seedRecord();
    $this->write('a', 1, doc((object) ['tags' => ['x'], 'title' => 'draft']), 1);
    $base = $this->record()->version->value;

    $this->write('alice', 1, doc((object) ['tags' => ['x'], 'title' => 'alice']), $base);
    $this->write('bob', 1, doc((object) ['tags' => ['x', 'y'], 'title' => 'draft']), $base);

    $group = $this->openConflicts()[0];
    $merged = (object) ['tags' => ['x', 'y'], 'title' => 'alice'];

    $resolution = $this->engine->process($this->mutation(
        'moderator', 1, doc($merged), $this->record()->version->value,
        kind: MutationKind::Resolve,
        resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates)),
    ));

    expect($resolution->status)->toBe(MutationStatus::Applied);
    expect($this->record()->value('doc')->value()->tags)->toBe(['x', 'y']);
    expect($this->record()->value('doc')->value()->title)->toBe('alice');
    expect($this->openConflicts())->toBeEmpty();
});

it('refuses a document that is not storable rather than truncating it', function () {
    $this->seedRecord();

    $deep = 'leaf';
    foreach (range(1, 70) as $ignored) {
        $deep = (object) ['next' => $deep];
    }

    expect(fn () => $this->write('a', 1, doc($deep), 1))
        ->toThrow(InvalidRequest::class);
});
