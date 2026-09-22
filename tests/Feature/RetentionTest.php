<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Tests\Fixtures\ViewScenario;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\ResetReason;
use Cbox\Sync\Views\ResetRequired;
use Cbox\Sync\Views\ViewCursor;
use Cbox\Sync\Views\ViewSyncService;

it('reports an empty space as retaining nothing and a full one as retaining everything', function () {
    expect($this->store->retainedFrom('test')->value)->toBe(0);
    expect($this->store->watermark('test')->value)->toBe(0);

    $this->seedRecord();
    expect($this->store->retainedFrom('test')->value)->toBe(1);
    expect($this->store->watermark('test')->value)->toBe(1);
});

it('keeps sequence numbering stable when history is pruned', function () {
    $this->seedRecord();
    foreach (range(1, 4) as $sequence) {
        $this->write('a', $sequence, [Op::set('title', 'title-'.$sequence)]);
    }
    expect($this->store->watermark('test')->value)->toBe(5);

    $this->store->prune('test', new CommitSequence(4));

    expect($this->store->watermark('test')->value)->toBe(5);
    expect($this->store->retainedFrom('test')->value)->toBe(4);
    expect($this->store->pull('test', 3)->commits)->toHaveCount(2);
    expect($this->store->commitsAfter('test', 3, 10))->toHaveCount(2);
});

it('refuses a cursor below the retention horizon instead of silently skipping history', function () {
    $this->seedRecord();
    foreach (range(1, 4) as $sequence) {
        $this->write('a', $sequence, [Op::set('title', 'title-'.$sequence)]);
    }
    $this->store->prune('test', new CommitSequence(4));

    expect(fn () => $this->store->pull('test', 1))->toThrow(HistoryUnavailable::class);
    expect(fn () => $this->store->commitsAfter('test', 1, 10))->toThrow(HistoryUnavailable::class);

    // The boundary itself is still serviceable: a client that consumed 3 can read 4.
    expect($this->store->pull('test', 3)->commits[0]->sequence->value)->toBe(4);
});

it('turns a pruned view cursor into a typed reset rather than an error', function () {
    $store = new InMemoryStore;
    $scenario = new ViewScenario($store);
    foreach (['a', 'b', 'c'] as $id) {
        $scenario->create($id, 'alpha');
    }
    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
    $sync = new ViewSyncService($store, 'v1', 'epoch-1');
    $stale = new ViewCursor($sync->context('test', $view), new CommitSequence(1));

    expect($sync->delta($stale, $view)->commits)->toHaveCount(2);

    $store->prune('test', new CommitSequence(3));

    try {
        $sync->delta($stale, $view);
        throw new LogicException('Expected a reset');
    } catch (ResetRequired $reset) {
        expect($reset->reason)->toBe(ResetReason::HistoryPruned);
    }
});

/**
 * The log was bounded by prune(); the receipts were not - one per mutation,
 * for ever. They go with the commits they were written in.
 */
it('prunes the receipts written in the commits it drops, and keeps the rest', function () {
    $this->seedRecord();
    foreach (range(1, 4) as $sequence) {
        $this->write('a', $sequence, [Op::set('title', 'title-'.$sequence)]);
    }

    $this->store->prune('test', new CommitSequence(4));

    expect($this->store->receipt('seed-1'))->toBeNull()
        ->and($this->store->receipt('a-2'))->toBeNull()
        ->and($this->store->receipt('a-3'))->not->toBeNull()
        ->and($this->store->receipt('a-4'))->not->toBeNull();
});

/**
 * Past the horizon a replay has no receipt to be answered from. It is told
 * where the stream is and applies nothing; a writer that renumbers and sends
 * it again is judged on its old base, so it meets the newer value as a
 * conflict rather than overwriting it.
 */
it('does not apply a replay whose receipt was pruned', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'once')]);
    $this->write('a', 2, [Op::set('title', 'twice')], base: 2);
    $this->store->prune('test', new CommitSequence(3));

    $replay = $this->write('a', 1, [Op::set('title', 'once')]);
    expect($replay->status)->toBe(MutationStatus::MutationGap)
        ->and($this->record()->value('title')->value())->toBe('twice');

    $renumbered = $this->write('a', 3, [Op::set('title', 'once')]);
    expect($renumbered->status)->toBe(MutationStatus::Conflict)
        ->and($this->record()->value('title')->value())->toBe('twice');
});

/** A dependency pruned with the log is no knowledge, not a refusal. */
it('treats a pruned dependency as no knowledge rather than refusing the write', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('body', 'mine')]);
    $this->store->prune('test', new CommitSequence(3));

    $dependent = $this->write('a', 2, [Op::set('title', 'next')], base: 2, dependsOn: 'a-1');

    expect($dependent->status)->toBe(MutationStatus::Applied);
});
