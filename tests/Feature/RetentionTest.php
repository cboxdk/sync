<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Exceptions\InvalidRequest;
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
 * Past the horizon a replay has no receipt to be answered from, and it cannot
 * be told apart from a new write. It is final, never renumbered - which would
 * apply it a second time - and the writer takes its own position as
 * acknowledged (Outbox::settledUnknown) and goes on with the next.
 */
it('refuses a replay whose receipt was pruned, and tells the writer where to go on from', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'once')]);
    $this->write('a', 2, [Op::set('title', 'twice')], base: 2);
    $this->store->prune('test', new CommitSequence(3));

    $replay = $this->write('a', 1, [Op::set('title', 'once')]);
    expect($replay->status)->toBe(MutationStatus::ReceiptPruned)
        ->and($replay->acknowledgedSequence)->toBe(2)
        ->and($this->record()->value('title')->value())->toBe('twice');

    // Position 2 was pruned too; 3 was never used.
    expect($this->write('a', 3, [Op::set('title', 'next')], base: 3)->status)->toBe(MutationStatus::Applied);
});

/**
 * The mark is exactly what was pruned. It used to be everything the stream
 * had acknowledged, so any retention run - even one that deleted nothing -
 * made a writer that was behind unable to write again.
 */
it('marks only the positions whose receipts were actually pruned', function () {
    $this->seedRecord();
    foreach (range(1, 4) as $sequence) {
        $this->write('a', $sequence, [Op::set('title', 't'.$sequence)], base: $sequence);
    }
    // Deletes the seed's and a-1's receipts only.
    $this->store->prune('test', new CommitSequence(3));

    // A restored writer reusing position 3 with a new identity: behind, not pruned.
    $behind = $this->engine->process($this->mutation('a', 3, [Op::set('title', 'new')], 5, id: 'fresh'));
    expect($behind->status)->toBe(MutationStatus::MutationGap)
        ->and($behind->reason)->toBe('sequence_behind')
        ->and($behind->acknowledgedSequence)->toBe(4);
});

/** A dependency pruned with the log is no knowledge, not a refusal. */
it('treats a pruned dependency as no knowledge rather than refusing the write', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('body', 'mine')]);
    $this->store->prune('test', new CommitSequence(3));

    $dependent = $this->write('a', 2, [Op::set('title', 'next')], base: 2, dependsOn: 'a-1');

    expect($dependent->status)->toBe(MutationStatus::Applied);
});

/**
 * The PDO ledger replaced the stream row on every acknowledgement, which reset
 * the pruned mark: after one more write, a pruned replay was renumbered and
 * applied again. The mark has to survive the stream moving on.
 */
it('still refuses a pruned replay after the stream has moved on', function () {
    $this->seedRecord();
    foreach (range(1, 5) as $sequence) {
        $this->write('a', $sequence, [Op::set('title', 't'.$sequence)], base: $sequence);
    }
    $this->store->prune('test', new CommitSequence(5));
    $this->write('a', 6, [Op::set('title', 'later')], base: 6);

    $replay = $this->write('a', 3, [Op::set('title', 't3')], base: 3);

    expect($replay->status)->toBe(MutationStatus::ReceiptPruned)
        ->and($this->record()->value('title')->value())->toBe('later');
});

/** A field name is a key too, and a NUL in one made PostgreSQL match another field's filter. */
it('holds field names to the identifier rules', function () {
    $this->seedRecord();

    expect(fn () => $this->write('a', 1, [Op::set("project\0x", 'alpha')]))->toThrow(InvalidRequest::class, 'NUL')
        ->and(fn () => $this->write('a', 1, [Op::set(str_repeat('f', 151), 'x')]))->toThrow(InvalidRequest::class);
});
