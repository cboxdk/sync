<?php

declare(strict_types=1);

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Client\InMemoryOutboxStore;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Client\Pdo\PdoOutboxStore;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Testing\Environment;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

function outboxStores(): array
{
    $stores = [
        'memory' => fn (): OutboxStore => new InMemoryOutboxStore,
        'sqlite' => function (): OutboxStore {
            $durable = new PdoOutboxStore(new PDO('sqlite::memory:'));
            $durable->migrate();

            return $durable;
        },
    ];
    // The configured database too: the outbox had never been installed on
    // MySQL, and nothing noticed because nothing ran it there.
    $dsn = Environment::get('SYNC_DSN');
    if ($dsn !== '') {
        $stores['database'] = function () use ($dsn): OutboxStore {
            $pdo = new PDO($dsn, Environment::get('SYNC_DB_USER'), Environment::get('SYNC_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $durable = new PdoOutboxStore($pdo);
            $durable->migrate();
            foreach (['sync_outbox', 'sync_outbox_names', 'sync_outbox_sequences'] as $table) {
                $pdo->exec('DELETE FROM '.$table);
            }

            return $durable;
        };
    }

    return $stores;
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

/**
 * The answer to pull_required. The rebased write keeps its identity and its
 * place: the server stored nothing for the first attempt, so it is still the
 * write the server is waiting for, and anything queued behind it stays behind.
 */
it('puts a rebased mutation in place of the original', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $key = new EntityKey('team-1', 'tasks', 'one');
    $first = $outbox->queue($key, MutationKind::Update, [Op::set('title', 'mine'), Op::set('body', 'b')], 1);
    $outbox->queue($key, MutationKind::Update, [Op::set('body', 'later')], 1);

    $head = $outbox->head();
    expect($head)->not->toBeNull();
    $outbox->rebase($head, new RecordVersion(4), [Op::set('title', 'merged')]);

    $again = $outbox->head();
    expect($again?->id)->toBe($first->id)
        ->and($again?->baseVersion->value)->toBe(4)
        ->and($again?->sequence->value)->toBe(1)
        ->and(array_map(fn (Op $op): string => $op->field.'='.$op->value->value(), $again->operations ?? []))->toBe(['title=merged'])
        ->and($outbox->pending())->toBe(2);
})->with(outboxStores());

it('rebases nothing that has already left the queue', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $key = new EntityKey('team-1', 'tasks', 'one');
    $outbox->queue($key, MutationKind::Update, [Op::set('title', 'mine')], 1);
    $head = $outbox->head();
    expect($head)->not->toBeNull();
    $outbox->acknowledged($head);

    $outbox->rebase($head, new RecordVersion(4), []);

    expect($outbox->pending())->toBe(0)->and($outbox->head())->toBeNull();
})->with(outboxStores());

/**
 * The server numbers per replica per ITS space, and maps type and scope to a
 * space by rules this device cannot see. So each (type, space) this device
 * writes to travels on its own replica identity: whatever the mapping, one
 * device stream meets exactly one server stream.
 */
it('gives every type and space its own stream', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('t', 't')], 0);
    $outbox->queue(new EntityKey('team-1', 'notes', 'n'), MutationKind::Create, [Op::set('t', 'n')], 0);
    $outbox->queue(new EntityKey('p1', 'notes', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);

    $tasks = $outbox->head('tasks', 'team-1');
    $notes = $outbox->head('notes', 'team-1');
    $other = $outbox->head('notes', 'p1');

    $streams = array_map(fn ($m) => $m?->replica->id, [$tasks, $notes, $other]);
    expect(array_unique($streams))->toHaveCount(3)
        ->and($tasks?->sequence->value)->toBe(1)
        ->and($notes?->sequence->value)->toBe(1)
        ->and($other?->sequence->value)->toBe(1)
        // Stable across calls and bounded, because the server stores it.
        ->and($outbox->stream(new EntityKey('team-1', 'tasks', 'x'))->id)->toBe($tasks?->replica->id)
        ->and(strlen((string) $tasks?->replica->id))->toBeLessThan(40);
})->with(outboxStores());

/** A push names a scope, and must not send another tenant's queued work under it. */
it('hands out only the space that was asked for', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-2', 'tasks', 'elsewhere'), MutationKind::Create, [Op::set('t', 'x')], 0);
    $outbox->queue(new EntityKey('team-1', 'tasks', 'here'), MutationKind::Create, [Op::set('t', 'y')], 0);

    expect($outbox->head('tasks', 'team-1')?->entity->id)->toBe('here')
        ->and($outbox->head('tasks', 'team-2')?->entity->id)->toBe('elsewhere')
        ->and($outbox->head('tasks', 'team-3'))->toBeNull();
})->with(outboxStores());

/**
 * A gap is only reported when this device is ahead of the server: a server
 * restored from a backup, say. A counter that could only rise would send the
 * same number, get the same gap, and never push again.
 */
it('numbers downward when the server turns out to be behind', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    for ($i = 0; $i < 5; $i++) {
        $outbox->queue(note('n'.$i), MutationKind::Create, [Op::set('t', 'x')], 0);
        $outbox->acknowledged($outbox->head() ?? throw new LogicException('expected a head'));
    }
    $outbox->queue(note('late'), MutationKind::Create, [Op::set('t', 'x')], 0);
    expect($outbox->head()?->sequence->value)->toBe(6);

    $outbox->resumeAfter($outbox->head() ?? throw new LogicException('expected a head'), 2);

    expect($outbox->head()?->sequence->value)->toBe(3);
})->with(outboxStores());

/**
 * A refusal about the moment, not the write - an expired session, a permission
 * granted since. The write goes again under a new identity, because the server
 * may hold a receipt for the old one.
 */
it('queues an abandoned write again as a new write', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Update, [Op::set('t', 'mine')], 3);
    $abandoned = $outbox->head() ?? throw new LogicException('expected a head');
    $outbox->abandon($abandoned, 'unauthenticated');

    $again = $outbox->requeue($abandoned->id);

    expect($again)->not->toBeNull()
        ->and($again?->id)->not->toBe($abandoned->id)
        ->and($outbox->abandoned())->toBe([])
        ->and($outbox->head()?->id)->toBe($again?->id)
        ->and($outbox->head()?->baseVersion->value)->toBe(3)
        ->and($outbox->head()?->operations[0]->value->value())->toBe('mine')
        ->and($outbox->requeue('no-such-write'))->toBeNull();
})->with(outboxStores());

it('stops reporting an abandoned write once it is dismissed', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->abandon($outbox->head() ?? throw new LogicException('expected a head'), 'forbidden');
    $id = $outbox->abandoned()[0]['mutation']->id;

    $outbox->dismiss($id);

    expect($outbox->abandoned())->toBe([])->and($outbox->pending())->toBe(0);
})->with(outboxStores());

/**
 * Two deliveries can overlap - a queue worker and a scheduler both draining.
 * A late acknowledgement of 1 arriving after one of 2 wound the counter back,
 * and the next write went out under a number already used.
 */
it('never winds the acknowledgement back for a late answer', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $first = $outbox->head() ?? throw new LogicException('expected a head');
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);
    $outbox->acknowledged($first);
    $second = $outbox->head() ?? throw new LogicException('expected a head');
    $outbox->acknowledged($second);

    // The first delivery's answer, arriving late.
    $store->setAcknowledged($outbox->stream(note('a')), 'team-1', 1);
    $outbox->queue(note('c'), MutationKind::Create, [Op::set('t', 'c')], 0);

    expect($outbox->head()?->sequence->value)->toBe(3);
})->with(outboxStores());

/**
 * The create leaves the queue and everything behind it is renamed in one
 * transaction, and the new name is kept - so a crash can never leave updates
 * addressed to a handle nothing resolves any more.
 */
it('renames what is queued behind a create in the same step that acknowledges it, and remembers the name', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $handle = note('handle');
    $outbox->queue($handle, MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue($handle, MutationKind::Update, [Op::set('t', 'b')], 1);
    $create = $outbox->head() ?? throw new LogicException('expected a head');

    $outbox->acknowledged($create, note('real'));

    expect($outbox->head()?->entity->id)->toBe('real')
        ->and($outbox->nameOf($handle)?->id)->toBe('real')
        ->and($outbox->nameOf(note('never-created')))->toBeNull();
})->with(outboxStores());

it('rolls the rename back with the acknowledgement when the step fails', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $durable = new class($pdo) extends PdoOutboxStore
    {
        public function rekey(EntityKey $from, EntityKey $to): void
        {
            parent::rekey($from, $to);

            throw new RuntimeException('the process died here');
        }
    };
    $durable->migrate();
    $outbox = outboxFor($durable);
    $handle = note('handle');
    $outbox->queue($handle, MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue($handle, MutationKind::Update, [Op::set('t', 'b')], 1);
    $create = $outbox->head() ?? throw new LogicException('expected a head');

    expect(fn () => $outbox->acknowledged($create, note('real')))->toThrow(RuntimeException::class);

    // All or nothing: the create is still there to be answered again.
    expect($outbox->pending())->toBe(2)
        ->and($outbox->head()?->id)->toBe($create->id)
        ->and($outbox->nameOf($handle))->toBeNull();
});

/**
 * A task created offline under a project also created offline carries the
 * project's handle. Renamed in the same step as the project's
 * acknowledgement, the task goes out pointing at the project's real id.
 */
it('rewrites fields that reference a created record, when told which fields do', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $project = new EntityKey('team-1', 'projects', 'project-handle');
    $outbox->queue($project, MutationKind::Create, [Op::set('name', 'Launch')], 0);
    $outbox->queue(new EntityKey('team-1', 'tasks', 'task-handle'), MutationKind::Create, [Op::set('project_id', 'project-handle'), Op::set('title', 'project-handle')], 0);
    $create = $outbox->head() ?? throw new LogicException('expected a head');

    // A tag with the same handle must not be mistaken for the project.
    $outbox->queue(new EntityKey('team-1', 'tasks', 'other-task'), MutationKind::Create, [Op::set('tag_id', 'project-handle')], 0);

    $outbox->acknowledged($create, new EntityKey('team-1', 'projects', 'project-real'), ['tasks' => ['project_id' => 'projects', 'tag_id' => 'tags']]);

    $task = $outbox->head('tasks') ?? throw new LogicException('expected the task');
    $values = [];
    foreach ($task->operations as $operation) {
        $values[$operation->field] = $operation->value->value();
    }
    // The declared reference is rewritten; a field that merely happens to
    // hold the same text is not.
    expect($values)->toBe(['project_id' => 'project-real', 'title' => 'project-handle']);

    $outbox->acknowledged($task);
    $other = $outbox->head('tasks') ?? throw new LogicException('expected the other task');
    expect($other->operations[0]->value->value())->toBe('project-handle');
})->with(outboxStores());

/**
 * A write queued before streams existed carries the bare device id. It goes
 * out on that stream, numbered where that stream left off, so its retry and
 * its depends_on still match what the server recorded.
 */
it('sends a write queued before the upgrade on the stream it was queued on', function (OutboxStore $store) {
    $legacy = new Replica('device-1');
    $store->append(new Mutation('old', note('a'), $legacy, new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [Op::set('t', 'x')]));
    $store->setAcknowledged($legacy, 'team-1', 4);
    $outbox = outboxFor($store);
    $outbox->queue(note('b'), MutationKind::Update, [Op::set('t', 'y')], 1);

    $head = $outbox->head() ?? throw new LogicException('expected a head');
    expect($head->replica->id)->toBe('device-1')->and($head->sequence->value)->toBe(5);

    $outbox->acknowledged($head);
    $next = $outbox->head() ?? throw new LogicException('expected the new write');
    expect($next->replica->id)->toBe($outbox->stream(note('b'))->id)->and($next->sequence->value)->toBe(1);
})->with(outboxStores());

/**
 * Two deliveries get the same gap; one resends and succeeds. The other's
 * late reset must not wind the counter back under it.
 */
it('ignores a resume that arrives after the stream has moved on', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $store->setAcknowledged($outbox->stream(note('a')), 'team-1', 3);
    $stale = $outbox->head() ?? throw new LogicException('expected a head');
    expect($stale->sequence->value)->toBe(4);

    // The first delivery: told the server is at 0, resends as 1, succeeds.
    $outbox->resumeAfter($stale, 0);
    $outbox->acknowledged($outbox->head() ?? throw new LogicException('expected a head'));
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    // The second, late with the same answer.
    $outbox->resumeAfter($stale, 0);

    expect($outbox->head()?->sequence->value)->toBe(2);
})->with(outboxStores());

it('makes a valid stream from a device id of any valid length', function () {
    $outbox = Outbox::for(new InMemoryOutboxStore, new Replica(str_repeat('d', 150)));
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);

    expect(strlen((string) $outbox->head()?->replica->id))->toBeLessThanOrEqual(150);
});

/** The server names a record; it never moves it. */
it('refuses a rename that changes the space or type', function (OutboxStore $store) {
    $outbox = outboxFor($store);

    expect(fn () => $outbox->rekey(note('a'), new EntityKey('team-2', 'notes', 'a')))->toThrow(InvalidRequest::class)
        ->and(fn () => $outbox->rekey(note('a'), new EntityKey('team-1', 'tasks', 'a')))->toThrow(InvalidRequest::class);
})->with(outboxStores());

/** A handle is the device's own; a child in one scope may point at a parent created in another. */
it('rewrites a reference to a record created in another scope', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $project = new EntityKey('team-1', 'projects', 'p-handle');
    $outbox->queue($project, MutationKind::Create, [Op::set('name', 'Launch')], 0);
    $outbox->queue(new EntityKey('other-scope', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p-handle')], 0);

    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected the project'), new EntityKey('team-1', 'projects', 'p-real'), ['tasks' => ['project_id' => 'projects']]);

    expect($outbox->head('tasks')?->operations[0]->value->value())->toBe('p-real');
})->with(outboxStores());

it('finds where writes for a record are still queued', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('p1', 'nodes', 'parent'), MutationKind::Create, [Op::set('t', 'a')], 0);

    expect($outbox->queuedKey('nodes', 'parent')?->space)->toBe('p1')
        ->and($outbox->queuedKey('nodes', 'other'))->toBeNull()
        ->and($outbox->queuedKey('tasks', 'parent'))->toBeNull();

    $outbox->acknowledged($outbox->head() ?? throw new LogicException('expected a head'));
    expect($outbox->queuedKey('nodes', 'parent'))->toBeNull();
})->with(outboxStores());

/** A write handed out may be on the server; rewriting it would make its retry a reused identity. */
it('never rewrites a write that has been handed out for sending', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p-handle')], 0);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p-handle'), MutationKind::Create, [Op::set('name', 'x')], 0);
    $inFlight = $outbox->head('tasks') ?? throw new LogicException('expected the task');

    $outbox->acknowledged($outbox->take($outbox->queuedCreate('projects', 'p-handle')?->id ?? '') ?? throw new LogicException('expected the project'), new EntityKey('team-1', 'projects', 'p-real'), ['tasks' => ['project_id' => 'projects']]);

    expect($outbox->head('tasks')?->operations[0]->value->value())->toBe('p-handle')
        ->and($outbox->head('tasks')?->id)->toBe($inFlight->id);
})->with(outboxStores());

/** A scope that is a parent's handle moves to the parent's name when the parent is named. */
it('moves writes scoped by a created record to its name', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p-handle'), MutationKind::Create, [Op::set('name', 'x')], 0);
    $outbox->queue(new EntityKey('p-handle', 'items', 'i'), MutationKind::Create, [Op::set('t', 'y')], 0);

    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected the project'), new EntityKey('team-1', 'projects', 'p-real'), scopedBy: ['items' => 'projects']);

    expect($outbox->peek('items', 'p-real')?->entity->id)->toBe('i')
        ->and($outbox->peek('items', 'p-handle'))->toBeNull();
})->with(outboxStores());

/** A requeued write goes back under the names the server gave everything it mentions. */
it('requeues under the names the server has given since', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p-handle'), MutationKind::Create, [Op::set('name', 'x')], 0);
    $outbox->queue(new EntityKey('p-handle', 'items', 'i'), MutationKind::Create, [Op::set('project_id', 'p-handle')], 0);
    $item = $outbox->head('items') ?? throw new LogicException('expected the item');
    $outbox->abandon($item, 'forbidden');
    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected the project'), new EntityKey('team-1', 'projects', 'p-real'));

    $again = $outbox->requeue($item->id, ['items' => ['project_id' => 'projects']], ['items' => 'projects']);

    expect($again?->entity->space)->toBe('p-real')
        ->and($again?->operations[0]->value->value())->toBe('p-real');
})->with(outboxStores());

/** A device database from before the id column: its rows are found by id after the upgrade. */
it('fills in the id column for rows queued before it existed', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoOutboxStore($pdo);
    $store->migrate();
    $outbox = outboxFor($store);
    $outbox->queue(note('legacy'), MutationKind::Create, [Op::set('t', 'x')], 0);
    $pdo->exec('UPDATE sync_outbox SET entity_id = NULL');

    $store->migrate();

    expect($outbox->queuedKey('notes', 'legacy')?->space)->toBe('team-1');
});

/**
 * A device restored from a backup replays writes whose answers were pruned.
 * Each one is final and takes its own position; jumping to the server's
 * position renumbered the rest above the pruned range and applied them again.
 */
it('settles a pruned replay at its own position, so the next replay keeps its number', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    foreach (['a', 'b'] as $id) {
        $outbox->queue(note($id), MutationKind::Update, [Op::set('t', $id)], 1);
    }

    $first = $outbox->head() ?? throw new LogicException('expected a head');
    $outbox->settledUnknown($first);
    $second = $outbox->head() ?? throw new LogicException('expected the next');

    expect($first->sequence->value)->toBe(1)
        ->and($second->sequence->value)->toBe(2)
        ->and($outbox->abandoned()[0]['reason'])->toBe('receipt_pruned');
})->with(outboxStores());

/** A write handed out keeps its number, and a stream sends a waiting write before numbering another. */
it('keeps a sent write\'s number and sends it before anything else on its stream', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('x'), MutationKind::Create, [Op::set('t', 'x')], 0);
    $outbox->queue(note('p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $parent = $outbox->take($outbox->queuedCreate('notes', 'p')?->id ?? '') ?? throw new LogicException('expected p');
    expect($parent->sequence->value)->toBe(1);

    // Its answer is lost. Whatever is asked for next on this stream, p goes again, as 1.
    $again = $outbox->head() ?? throw new LogicException('expected a head');

    expect($again->id)->toBe($parent->id)->and($again->sequence->value)->toBe(1);
})->with(outboxStores());

/**
 * What was looked up before the transaction may be stale: another process can
 * have rewritten a reference in it since. Handing out that copy undid the
 * rewrite, and every retry sent the handle.
 */
it('hands out the write as it is now, not as it was when it was looked up', function () {
    $pdo = new PDO('sqlite::memory:');
    $stale = new class($pdo) extends PdoOutboxStore
    {
        public ?Mutation $frozen = null;

        public function head(?string $entityType = null, ?string $space = null): ?Mutation
        {
            return $this->frozen ?? parent::head($entityType, $space);
        }
    };
    $stale->migrate();
    $outbox = outboxFor($stale);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p-handle'), MutationKind::Create, [Op::set('name', 'x')], 0);
    $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p-handle')], 0);
    $stale->frozen = $stale->head('tasks');

    // Meanwhile the parent is named and the child rewritten.
    $outbox->acknowledged($outbox->take($outbox->queuedCreate('projects', 'p-handle')?->id ?? '') ?? throw new LogicException('expected the project'), new EntityKey('team-1', 'projects', 'p-real'), ['tasks' => ['project_id' => 'projects']]);

    $sent = $outbox->head('tasks');
    expect($sent?->operations[0]->value->value())->toBe('p-real');
});
