<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Resolvers\RejectOnConflict;

/**
 * Pins the feed shape the storage refactor must not change by accident: which
 * mutations consume a commit sequence, which emit canonical or conflict changes,
 * and in what order. The engine currently derives all of this from whole-map
 * comparisons in Engine::process(); a keyed contract has to reproduce it.
 */
it('emits conflict changes in group creation order, not in operation order', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('a', 2, [Op::set('body', 'B')]);
    $this->write('b', 1, [Op::set('title', 'X')]);

    // 'body' conflicts first and opens a new group; 'title' conflicts second and
    // joins the group opened above, which keeps its earlier position.
    $result = $this->write('c', 1, [Op::set('body', 'Y'), Op::set('title', 'Z')]);
    expect($result->status)->toBe(MutationStatus::Conflict);
    expect($result->conflictGroupIds)->toBe(['generated-2', 'generated-1']);

    $changes = $this->lastCommit()->changes;
    expect($changes)->toHaveCount(3);
    expect(array_map(fn ($change): ?string => $change->group?->field, $changes))->toBe(['title', 'body', null]);
    expect($changes[2]->kind)->toBe(ChangeKind::Mutation);
    expect(array_map(fn ($change): int => $change->ordinal, $changes))->toBe([0, 1, 2]);
});

it('emits only a receipt for a no-op and does not advance the canonical version', function () {
    $this->seedRecord();
    $before = $this->record()->version->value;

    $result = $this->write('a', 1, [Op::set('title', 'initial')]);
    expect($result->status)->toBe(MutationStatus::Noop);
    expect($this->record()->version->value)->toBe($before);

    $changes = $this->lastCommit()->changes;
    expect($changes)->toHaveCount(1);
    expect($changes[0]->kind)->toBe(ChangeKind::Mutation);
});

it('emits only a receipt for a rejection and preserves no candidate', function () {
    $this->setUpSync(new RejectOnConflict);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);

    $result = $this->write('b', 1, [Op::set('title', 'B')]);
    expect($result->status)->toBe(MutationStatus::Rejected);
    expect($this->openConflicts())->toBeEmpty();

    $changes = $this->lastCommit()->changes;
    expect($changes)->toHaveCount(1);
    expect($changes[0]->kind)->toBe(ChangeKind::Mutation);
});

it('consumes no commit sequence for a mutation gap', function () {
    $this->seedRecord();
    $consumed = count($this->commits());

    $result = $this->write('a', 3, [Op::set('title', 'A')]);
    expect($result->status)->toBe(MutationStatus::MutationGap);
    expect($this->commits())->toHaveCount($consumed);

    // The next in-order mutation takes the sequence the gap did not burn.
    $accepted = $this->write('a', 1, [Op::set('title', 'A')]);
    expect($accepted->commitSequence?->value)->toBe($consumed + 1);
});

it('consumes no commit sequence for a replayed mutation', function () {
    $this->seedRecord();
    $mutation = $this->mutation('a', 1, [Op::set('title', 'A')]);
    $first = $this->engine->process($mutation);
    $consumed = count($this->commits());

    foreach (range(1, 3) as $_) {
        expect($this->engine->process($mutation))->toEqual($first);
    }
    expect($this->commits())->toHaveCount($consumed);
});
