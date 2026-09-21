<?php

declare(strict_types=1);

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Client\InMemoryOutboxStore;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Client\Pdo\PdoOutboxStore;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
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
    $outbox->resumeAfter($outbox->head() ?? throw new LogicException('expected a head'), 4);

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

it('keeps one acknowledgement stream per space', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $inA = new EntityKey('space-a', 'notes', 'a');
    $inB = new EntityKey('space-b', 'notes', 'b');

    $outbox->queue($inA, MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue($inB, MutationKind::Create, [Op::set('t', 'b')], 0);

    $first = $outbox->head() ?? throw new LogicException('expected a head');
    expect($first->sequence->value)->toBe(1);
    $outbox->acknowledged($first);

    // Space B has never seen a mutation from this device, so its stream starts
    // at one too. A single per-replica counter would offer it 2 and be told it
    // is a gap, forever.
    $second = $outbox->head() ?? throw new LogicException('expected a head');
    expect($second->entity->space)->toBe('space-b');
    expect($second->sequence->value)->toBe(1);
})->with(outboxStores());

it('hands out only the entity type that was asked for', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('space-a', 'notes', 'n'), MutationKind::Create, [Op::set('t', 'n')], 0);
    $outbox->queue(new EntityKey('space-a', 'tasks', 't'), MutationKind::Create, [Op::set('t', 't')], 0);

    expect($outbox->head('tasks')?->entity->id)->toBe('t');
    expect($outbox->head('notes')?->entity->id)->toBe('n');
    expect($outbox->pending('tasks'))->toBe(1);
    expect($outbox->pending())->toBe(2);
})->with(outboxStores());

/**
 * A create carries a handle the device made up, and the server answers with the
 * name it gave the record. Everything queued behind that create still refers to
 * the handle, and would be a write to a record that does not exist.
 */
it('renames the record every queued mutation refers to', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $handle = new EntityKey('team-1', 'tasks', 'handle-1');
    $other = new EntityKey('team-1', 'tasks', 'untouched');

    $outbox->queue($handle, MutationKind::Create, [Op::set('title', 'a')], 0);
    $outbox->queue($handle, MutationKind::Update, [Op::set('title', 'b')], 1);
    $outbox->queue($other, MutationKind::Create, [Op::set('title', 'c')], 0);

    $named = new EntityKey('team-1', 'tasks', 'server-name');
    $outbox->rekey($handle, $named);

    $seen = [];
    while (($head = $outbox->head()) !== null) {
        $seen[] = $head->entity->id;
        $outbox->acknowledged($head);
    }

    // The two behind the handle now name the record the server made, and the
    // mutation identities are untouched - renaming what a write targets is not
    // a new write.
    expect($seen)->toBe(['server-name', 'server-name', 'untouched']);
})->with(outboxStores());

it('leaves the queue alone when the name did not change', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $key = new EntityKey('team-1', 'tasks', 'same');
    $outbox->queue($key, MutationKind::Create, [Op::set('title', 'a')], 0);

    $outbox->rekey($key, new EntityKey('team-1', 'tasks', 'same'));

    expect($outbox->head()?->entity->id)->toBe('same');
    expect($outbox->pending())->toBe(1);
})->with(outboxStores());

/**
 * A mutation identity is burned once it is used. Both stores refuse a second
 * one, and refuse it the same way: a raw driver exception escaping the
 * package's own hierarchy left a caller unable to tell it from a disk error.
 */
it('refuses to queue an identity it already holds', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $key = new EntityKey('team-1', 'tasks', 'one');
    $first = $outbox->queue($key, MutationKind::Create, [Op::set('title', 'a')], 0);

    expect(fn () => $store->append($first))->toThrow(InvalidRequest::class);
    expect($outbox->pending())->toBe(1);
})->with(outboxStores());

/** Abandoning keeps the identity burned, on both. */
it('refuses to re-queue an identity it abandoned', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $key = new EntityKey('team-1', 'tasks', 'one');
    $mutation = $outbox->queue($key, MutationKind::Create, [Op::set('title', 'a')], 0);
    $outbox->abandon($mutation, 'forbidden');

    expect(fn () => $store->append($mutation))->toThrow(InvalidRequest::class);
})->with(outboxStores());
