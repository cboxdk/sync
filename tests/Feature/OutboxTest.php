<?php

declare(strict_types=1);

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Client\InMemoryOutboxStore;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Client\Pdo\PdoOutboxStore;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

function outboxStores(): array
{
    $durable = new PdoOutboxStore(new PDO('sqlite::memory:'));
    $durable->migrate();

    return ['memory' => new InMemoryOutboxStore, 'sqlite' => $durable];
}

function outboxFor(OutboxStore $store, string $prefix = 'm'): Outbox
{
    $n = 0;

    // A real client mints globally unique ids; the prefix stands in for the
    // fact that a restarted process must not reissue the ones already queued.
    return Outbox::for($store, new Replica('device-1'), function () use (&$n, $prefix): string {
        return $prefix.(++$n);
    });
}

function note(string $id): EntityKey
{
    return new EntityKey('team-1', 'notes', $id);
}

it('assigns gapless sequences and never reuses one', function (OutboxStore $store) {
    $outbox = outboxFor($store);

    $first = $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $second = $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    expect([$first->sequence->value, $second->sequence->value])->toBe([1, 2]);

    // Acknowledging the first must not free its number: the server treats a
    // repeated sequence as a protocol error, not a retry.
    $outbox->acknowledged($first);
    expect($outbox->queue(note('c'), MutationKind::Create, [Op::set('t', 'c')], 0)->sequence->value)->toBe(3);
})->with(outboxStores());

it('hands out the oldest pending mutation first', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $first = $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    expect($outbox->head()?->id)->toBe($first->id);
    $outbox->acknowledged($first);
    expect($outbox->head()?->id)->not->toBe($first->id);
    expect($outbox->pending())->toBe(1);
})->with(outboxStores());

it('drops only what the server already has when it reports a gap', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);
    $third = $outbox->queue(note('c'), MutationKind::Create, [Op::set('t', 'c')], 0);

    // The server acknowledged through 2; 3 was never received and must survive.
    $outbox->resumeAfter(2);

    expect($outbox->pending())->toBe(1);
    expect($outbox->head()?->id)->toBe($third->id);
})->with(outboxStores());

it('moves a terminal mutation out of the way instead of blocking the queue', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $poison = $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $next = $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    $outbox->abandon($poison, 'protocol_violation');

    expect($outbox->head()?->id)->toBe($next->id);
    expect($outbox->abandoned())->toHaveCount(1);
    expect($outbox->abandoned()[0]['reason'])->toBe('protocol_violation');
})->with(outboxStores());

it('survives a restart with its queue and its sequence intact', function () {
    $database = tempnam(sys_get_temp_dir(), 'cbox-outbox-').'.sqlite';
    try {
        $open = function () use ($database): PdoOutboxStore {
            $store = new PdoOutboxStore(new PDO('sqlite:'.$database));
            $store->migrate();

            return $store;
        };
        $before = outboxFor($open());
        $before->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
        $queued = $before->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

        // A new process, nothing carried in memory.
        $after = outboxFor($open(), 'restarted-');

        expect($after->pending())->toBe(2);
        expect($after->head()?->entity->id)->toBe('a');
        expect($after->queue(note('c'), MutationKind::Create, [Op::set('t', 'c')], 0)->sequence->value)
            ->toBe($queued->sequence->value + 1);
    } finally {
        @unlink($database);
    }
});
