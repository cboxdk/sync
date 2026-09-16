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

it('numbers mutations only as they are sent, in order', function (OutboxStore $store) {
    $outbox = outboxFor($store);

    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    $first = $outbox->head() ?? throw new LogicException('expected a head');
    expect($first->sequence->value)->toBe(1);

    // Still 1 until the server acknowledges: a number is spent on acceptance,
    // never on an attempt.
    expect($outbox->head()?->sequence->value)->toBe(1);

    $outbox->acknowledged($first);
    expect($outbox->head()?->sequence->value)->toBe(2);
})->with(outboxStores());

it('leaves no hole when a mutation is never accepted', function (OutboxStore $store) {
    // The bug this prevents: a write the transport refuses has already taken a
    // sequence number, the server never sees it, and every later write comes
    // back as a gap for a number that will never arrive.
    $outbox = outboxFor($store);
    $outbox->queue(note('poison'), MutationKind::Create, [Op::set('t', 'refused')], 0);
    $outbox->queue(note('good'), MutationKind::Create, [Op::set('t', 'fine')], 0);

    $poison = $outbox->head() ?? throw new LogicException('expected a head');
    expect($poison->sequence->value)->toBe(1);
    $outbox->abandon($poison, 'field_not_writable');

    // The next write takes the number the refused one did not.
    expect($outbox->head()?->sequence->value)->toBe(1);
    expect($outbox->head()?->entity->id)->toBe('good');
})->with(outboxStores());

it('hands out the oldest pending mutation first', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    $first = $outbox->head() ?? throw new LogicException('expected a head');
    expect($first->entity->id)->toBe('a');
    $outbox->acknowledged($first);
    expect($outbox->head()?->entity->id)->toBe('b');
    expect($outbox->pending())->toBe(1);
})->with(outboxStores());

it('numbers from where the server says it is after a gap', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);

    // The server already has through 4, from a session this device forgot.
    $outbox->resumeAfter(4);

    expect($outbox->pending())->toBe(1);
    expect($outbox->head()?->sequence->value)->toBe(5);
})->with(outboxStores());

it('moves a terminal mutation out of the way instead of blocking the queue', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    $outbox->abandon($outbox->head() ?? throw new LogicException('expected a head'), 'protocol_violation');

    expect($outbox->head()?->entity->id)->toBe('b');
    expect($outbox->abandoned())->toHaveCount(1);
    expect($outbox->abandoned()[0]['reason'])->toBe('protocol_violation');
})->with(outboxStores());

it('survives a restart with its queue and its numbering intact', function () {
    $database = tempnam(sys_get_temp_dir(), 'cbox-outbox-').'.sqlite';
    try {
        $open = function () use ($database): PdoOutboxStore {
            $store = new PdoOutboxStore(new PDO('sqlite:'.$database));
            $store->migrate();

            return $store;
        };
        $before = outboxFor($open());
        $before->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
        $before->acknowledged($before->head() ?? throw new LogicException('expected a head'));
        $before->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

        // A new process, nothing carried in memory.
        $after = outboxFor($open(), 'restarted-');

        expect($after->pending())->toBe(1);
        expect($after->head()?->entity->id)->toBe('b');
        expect($after->head()?->sequence->value)->toBe(2);
    } finally {
        @unlink($database);
    }
});
