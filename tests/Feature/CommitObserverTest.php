<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\CommitObserver;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/** Records what it was told, and can be made to fail. */
final class RecordingObserver implements CommitObserver
{
    /** @var list<array{string, int}> */
    public array $signals = [];

    public function __construct(public bool $throw = false) {}

    public function committed(string $space, CommitSequence $watermark): void
    {
        $this->signals[] = [$space, $watermark->value];
        if ($this->throw) {
            throw new RuntimeException('listener exploded');
        }
    }
}

function write(Engine $engine, string $id, int $sequence, array $operations, MutationKind $kind = MutationKind::Create, int $base = 0): mixed
{
    return $engine->process(new Mutation(
        'm-'.$id.'-'.$sequence, new EntityKey('team-1', 'notes', $id), new Replica('device'),
        new MutationSequence($sequence), $kind, new RecordVersion($base), $operations,
    ));
}

it('says a space advanced, and how far', function () {
    $observer = new RecordingObserver;
    $engine = new Engine($this->syncStore(), observer: $observer);

    write($engine, 'a', 1, [Op::set('title', 'one')]);
    write($engine, 'b', 2, [Op::set('title', 'two')]);

    expect($observer->signals)->toBe([['team-1', 1], ['team-1', 2]]);
});

/**
 * A replay appends no commit, so there is nothing new to announce. Waking every
 * device for a write that did not happen is the cheapest way to turn a retry
 * storm into a thundering herd.
 */
it('says nothing when a mutation is replayed', function () {
    $observer = new RecordingObserver;
    $engine = new Engine($this->syncStore(), observer: $observer);

    write($engine, 'a', 1, [Op::set('title', 'one')]);
    write($engine, 'a', 1, [Op::set('title', 'one')]);

    expect($observer->signals)->toHaveCount(1);
});

/** A gap is refused before anything is written. */
it('says nothing when a mutation is refused', function () {
    $observer = new RecordingObserver;
    $engine = new Engine($this->syncStore(), observer: $observer);

    write($engine, 'a', 5, [Op::set('title', 'one')]);

    expect($observer->signals)->toBe([]);
});

/**
 * The signal is sent after the write is durable, so a listener cannot unmake
 * it - which means a listener that throws must not be able to fail the write
 * either, or a broken notifier becomes a broken database.
 */
it('has already committed by the time it tells anyone', function () {
    $observer = new RecordingObserver(throw: true);
    $store = $this->syncStore();
    $engine = new Engine($store, observer: $observer);

    $result = write($engine, 'a', 1, [Op::set('title', 'one')]);

    // The caller is told the write applied, because it did. Failing the push
    // here would only make the client retry, meet its own receipt, and be told
    // the same thing again.
    expect($result->status)->toBe(MutationStatus::Applied);
    expect($observer->signals)->toHaveCount(1);
    expect($store->record(new EntityKey('team-1', 'notes', 'a')))->not->toBeNull();
    expect($store->watermark('team-1')->value)->toBe(1);
});
