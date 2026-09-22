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
        public function rekey(EntityKey $from, EntityKey $to, bool $creates = true): void
        {
            parent::rekey($from, $to, $creates);

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

/**
 * A late copy of a gap answer, arriving after the write went out again under
 * its new number, used to clear that newer attempt's sent mark - and the write
 * that had landed was later numbered afresh and refused as a reused identity.
 */
it('keeps a newer attempt\'s number when a late gap answer arrives', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $store->setAcknowledged($outbox->stream(note('a')), 'team-1', 3);
    $stale = $outbox->head() ?? throw new LogicException('expected a head');

    $outbox->resumeAfter($stale, 0);
    $resent = $outbox->head() ?? throw new LogicException('expected the resend');
    // Its answer is lost; then the late copy of the first gap arrives.
    $outbox->resumeAfter($stale, 0);

    expect($resent->sequence->value)->toBe(1)
        ->and($store->isSent($resent->id))->toBeTrue()
        ->and($outbox->head()?->sequence->value)->toBe(1);
})->with(outboxStores());

/**
 * A handle is only unique within its space. Tenant B's update of its own
 * `local-1` was rewritten to the name tenant A's `local-1` got, and applied to
 * A's record.
 */
it('never takes another space\'s name for a record\'s own handle', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $a = new EntityKey('team-a', 'notes', 'local-1');
    $b = new EntityKey('team-b', 'notes', 'local-1');
    $outbox->queue($a, MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->acknowledged($outbox->head('notes', 'team-a') ?? throw new LogicException('expected a'), new EntityKey('team-a', 'notes', '42'));
    $update = $outbox->queue($b, MutationKind::Update, [Op::set('t', 'b')], 3);

    $outbox->mapNames($update, [], []);

    expect($outbox->head('notes', 'team-b')?->entity->id)->toBe('local-1');
})->with(outboxStores());

/** A handle reused for a new record still waiting to be created is that record, not the one named before. */
it('does not point a reference at an old record when its handle is being created again', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'draft'), MutationKind::Create, [Op::set('t', 'old')], 0);
    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected a create'), new EntityKey('team-1', 'projects', '42'));
    $outbox->queue(new EntityKey('team-1', 'projects', 'draft'), MutationKind::Create, [Op::set('t', 'new')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'draft')], 0);

    $outbox->mapNames($child, ['tasks' => ['project_id' => 'projects']], []);

    expect($outbox->head('tasks')?->operations[0]->value->value())->toBe('draft');
})->with(outboxStores());

/**
 * A write an earlier release sent and never heard back about carries the
 * placeholder number 1 - that release numbered on every attempt. Trusting it
 * resent the write as 1, a number the server had long given to another.
 */
it('numbers a write an earlier release left in flight the way that release would have', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoOutboxStore($pdo);
    $store->migrate();
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $store->setAcknowledged($outbox->stream(note('a')), 'team-1', 7);
    // As the earlier release left it: flagged, payload still at placeholder 1.
    $pdo->exec('UPDATE sync_outbox SET attempted = 1, numbered = 0');

    $store->migrate();

    expect($outbox->head()?->sequence->value)->toBe(8);
});

/** A refused write is kept, under its reason, not dropped with the push's report of it. */
it('keeps a refused write as abandoned and moves the stream on', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->queue(note('b'), MutationKind::Create, [Op::set('t', 'b')], 0);

    $outbox->refused($outbox->head() ?? throw new LogicException('expected a'), 'validation_failed');

    expect($outbox->abandoned()[0]['reason'])->toBe('validation_failed')
        ->and($outbox->createAbandoned('notes', 'a'))->toBeTrue()
        ->and($outbox->head()?->sequence->value)->toBe(2);
})->with(outboxStores());

/** Once a refused create is dismissed nothing else said the record will never exist; its dependants went out with its handle. */
it('takes a dismissed create\'s dependants with it', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $edit = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Update, [Op::set('t', 'q')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $nested = $outbox->queue(new EntityKey('p', 'items', 'i'), MutationKind::Create, [Op::set('t', 'i')], 0);
    $other = $outbox->queue(new EntityKey('team-1', 'tasks', 'u'), MutationKind::Create, [Op::set('project_id', 'elsewhere')], 0);
    $create = $outbox->head('projects') ?? throw new LogicException('expected the create');
    $outbox->refused($create, 'forbidden');

    $outbox->dismiss($create->id, ['tasks' => ['project_id' => 'projects']], ['items' => 'projects']);

    $reasons = [];
    foreach ($outbox->abandoned() as $entry) {
        $reasons[$entry['mutation']->id] = $entry['reason'];
    }
    expect($reasons)->toBe([$edit->id => 'parent_abandoned', $child->id => 'parent_abandoned', $nested->id => 'parent_abandoned'])
        ->and($outbox->pending())->toBe(1)
        ->and($outbox->head('tasks')?->id)->toBe($other->id);
})->with(outboxStores());

/** A write that may already be on the server is not sent again under a new identity without someone saying so. */
it('refuses to requeue a write that may already have landed', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->settledUnknown($write);

    expect(fn () => $outbox->requeue($write->id))->toThrow(InvalidRequest::class)
        ->and($outbox->requeue($write->id, evenIfItMayHaveLanded: true))->not->toBeNull();
})->with(outboxStores());

/** A late copy of a receipt_pruned answer settled writes queued since - which had never gone anywhere. */
it('ignores a late copy of the answer that settled a restored stream', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Update, [Op::set('t', 'a')], 1);
    $first = $outbox->head() ?? throw new LogicException('expected a');
    expect($outbox->settledUnknown($first, serverAcknowledged: 500))->toBe(1);
    $fresh = $outbox->queue(note('d'), MutationKind::Update, [Op::set('t', 'd')], 1);

    expect($outbox->settledUnknown($first, serverAcknowledged: 500))->toBe(1)
        ->and($outbox->head()?->id)->toBe($fresh->id);
})->with(outboxStores());

/** A stream from before streams were split by type carries several types; one left behind went out past the server and could apply twice. */
it('settles every type on a restored stream from before streams were split', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $legacy = new Replica('device-1');
    foreach (['notes' => 'n1', 'tasks' => 't1'] as $type => $id) {
        $store->append(new Mutation('legacy-'.$id, new EntityKey('team-1', $type, $id), $legacy, new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [Op::set('t', $id)]));
    }
    $first = $outbox->head('notes') ?? throw new LogicException('expected the note');

    expect($outbox->settledUnknown($first, serverAcknowledged: 500))->toBe(2)
        ->and($outbox->pending())->toBe(0);
})->with(outboxStores());

/** A create that may have landed probably exists; dismissing its report must not abandon the edits that need it. */
it('holds back the edits of a dismissed create that may have landed as parent_unknown', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $edit = $outbox->queue(note('a'), MutationKind::Update, [Op::set('t', 'b')], 1);
    $create = $outbox->head() ?? throw new LogicException('expected the create');
    $outbox->settledUnknown($create);

    $outbox->dismiss($create->id);

    // It may exist, but this device never learned its name: its edits would
    // go out under a handle the server never heard of.
    expect($outbox->abandoned())->toHaveCount(1)
        ->and($outbox->abandoned()[0]['mutation']->id)->toBe($edit->id)
        ->and($outbox->abandoned()[0]['reason'])->toBe('parent_unknown');
})->with(outboxStores());

/**
 * A write sent more than once got no answer at least once, so it may be on the
 * server whatever the last answer said - a create applied, then refused on the
 * resend because the user had lost access, was a second record when requeued.
 */
it('treats a write refused on a resend as one that may have landed', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->head(); // the resend, after an answer that never came
    $outbox->abandon($write, 'forbidden');

    expect($store->unanswered($write->id))->toBe(1)
        ->and(fn () => $outbox->requeue($write->id))->toThrow(InvalidRequest::class);
})->with(outboxStores());

/**
 * An answered sending did not land, whatever it said - "busy", "sign in
 * again" - and a gap proves nothing landed at all. Counting those as possible
 * landings blocked a user's legitimate requeue and turned off the cascade that
 * stops orphaned children.
 */
it('counts only sendings that got no answer as possibly landed', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->answered($write); // 401: sign in again
    $outbox->head();
    $outbox->answered($write); // 503: busy
    $outbox->head();
    $outbox->abandon($write, 'forbidden');

    expect($store->unanswered($write->id))->toBe(0)
        ->and($outbox->requeue($write->id))->not->toBeNull();
})->with(outboxStores());

it('forgets unanswered sendings once a gap proves none of them landed', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $store->setAcknowledged($outbox->stream(note('a')), 'team-1', 3);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->head(); // its answer was lost
    $outbox->resumeAfter($write, 0);

    expect($store->unanswered($write->id))->toBe(0);
})->with(outboxStores());

/** Dismissing through the outbox directly, without the relations, used to release a refused parent's children with its handle. */
it('uses the relations it was given when dismiss is not told them', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $create = $outbox->head('projects') ?? throw new LogicException('expected the create');
    $outbox->refused($create, 'validation_failed');

    $outbox->dismiss($create->id);

    expect($outbox->abandoned()[0]['mutation']->id)->toBe($child->id);
})->with(outboxStores());

/** The server keeps an answer for every write it processed, so a processed refusal proves no sending of it applied. */
it('clears the unanswered count when the server processed and refused the write', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->head(); // the first answer was lost
    $outbox->refused($write, 'validation_failed');

    expect($store->unanswered($write->id))->toBe(0)
        ->and($outbox->requeue($write->id))->not->toBeNull();
})->with(outboxStores());

/** A write given up on without an answer may still have landed. */
it('keeps a write abandoned without an answer as possibly landed', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->abandon($write, 'timed_out', answered: false);

    expect(fn () => $outbox->requeue($write->id))->toThrow(InvalidRequest::class);
})->with(outboxStores());

/** An earlier release kept no record of its sendings, so a write queued before the upgrade may have gone out unanswered. */
it('counts a write queued before sendings were recorded as possibly sent', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoOutboxStore($pdo);
    $store->migrate();
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $pdo->exec('ALTER TABLE sync_outbox DROP COLUMN sends');

    $store->migrate();

    expect($store->unanswered('m1'))->toBe(1);
});

/**
 * A child abandoned as parent_unknown was requeued as it was, and went out
 * carrying the handle of a parent the server knows by another name - or not at
 * all. It waits until the application records what the parent was called.
 */
it('requeues a parent_unknown child only once its parent\'s name is found', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $create = $outbox->head('projects') ?? throw new LogicException('expected the create');
    $outbox->settledUnknown($create);
    $outbox->dismiss($create->id);

    expect(fn () => $outbox->requeue($child->id))->toThrow(InvalidRequest::class);

    $outbox->found(new EntityKey('team-1', 'projects', 'p'), 'srv-42');
    $requeued = $outbox->requeue($child->id);

    expect($requeued?->operations[0]->value->value())->toBe('srv-42');
})->with(outboxStores());

/**
 * Once a refused create was dismissed nothing remembered its handle, so a
 * child requeued afterwards went out pointing at a record the server never
 * heard of. The dismissed create is kept, unreported, and still says so.
 */
it('refuses to requeue a child of a dismissed create until that create is requeued', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $parent = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $outbox->refused($outbox->head('projects') ?? throw new LogicException('expected the create'), 'forbidden');
    $outbox->dismiss($parent->id);

    expect($outbox->abandoned())->toHaveCount(1)
        ->and(fn () => $outbox->requeue($child->id))->toThrow(InvalidRequest::class)
        ->and($outbox->orphanReason('projects', 'p'))->toBe('parent_abandoned');
})->with(outboxStores());

/** A server that keeps the device's id names a record by its handle; found() with that name unblocks its children. */
it('unblocks a child when found() records the handle itself as the name', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $parent = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $outbox->settledUnknown($outbox->head('projects') ?? throw new LogicException('expected the create'));
    $outbox->dismiss($parent->id);

    $outbox->found(new EntityKey('team-1', 'projects', 'p'), 'p');

    expect($outbox->requeue($child->id)?->operations[0]->value->value())->toBe('p');
})->with(outboxStores());

/** One parent found released a child still carrying the other parent's handle. */
it('keeps a child blocked while any parent it needs is still unnamed', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects', 'owner_id' => 'people']], []);
    $project = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $person = $outbox->queue(new EntityKey('team-1', 'people', 'u'), MutationKind::Create, [Op::set('t', 'u')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p'), Op::set('owner_id', 'u')], 0);
    foreach (['projects' => $project, 'people' => $person] as $type => $create) {
        $outbox->settledUnknown($outbox->head($type) ?? throw new LogicException('expected '.$type));
        $outbox->dismiss($create->id);
    }

    $outbox->found(new EntityKey('team-1', 'projects', 'p'), 'srv-p');

    expect(fn () => $outbox->requeue($child->id))->toThrow(InvalidRequest::class);
})->with(outboxStores());

/** found() renames only a create this device gave up on - never a queued create under way, nor an id another tenant happens to share. */
it('refuses found() for anything but an abandoned create of this device', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);

    expect(fn () => $outbox->found(new EntityKey('team-1', 'projects', 'p'), 'srv'))->toThrow(InvalidRequest::class)
        ->and(fn () => $outbox->found(new EntityKey('team-2', 'projects', '42'), 'srv'))->toThrow(InvalidRequest::class);
})->with(outboxStores());

/** Children a push abandoned before anyone knew their parent might exist are relabelled when it is dismissed as possibly landed. */
it('relabels children already abandoned when their parent turns out to have maybe landed', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $parent = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'p')], 0);
    $child = $outbox->queue(new EntityKey('team-1', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $outbox->settledUnknown($outbox->head('projects') ?? throw new LogicException('expected the create'));
    // As a push abandons it: looked at, never handed out.
    $outbox->abandon($outbox->peek('tasks') ?? throw new LogicException('expected the child'), 'parent_abandoned', answered: false);

    $outbox->dismiss($parent->id);

    expect($outbox->abandoned()[0]['mutation']->id)->toBe($child->id)
        ->and($outbox->abandoned()[0]['reason'])->toBe('parent_unknown')
        ->and($outbox->mayHaveLanded($child->id))->toBeFalse();
})->with(outboxStores());

/**
 * A dismissed create kept blocking a record created again under the same id:
 * the server accepted the new one and kept the id, no name was recorded, and
 * every later edit and child was abandoned as needing the dismissed one.
 */
it('stops a dismissed create blocking a record created again under its id', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $first = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'bad')], 0);
    $outbox->refused($outbox->head('projects') ?? throw new LogicException('expected the create'), 'validation_failed');
    $outbox->dismiss($first->id);

    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'good')], 0);
    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected the new create'));

    expect($outbox->orphanReason('projects', 'p'))->toBeNull()
        ->and($store->abandonedCreate('projects', 'p'))->toBeNull();
})->with(outboxStores());

/** A reason that happened to start like the old dismissed marker hid the write for good. */
it('reports an abandoned write whatever its reason says', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->refused($outbox->head() ?? throw new LogicException('expected a'), 'dismissed:by_server');

    expect($outbox->abandoned())->toHaveCount(1);
})->with(outboxStores());

/** A late refusal of a write the application already dismissed reported it again. */
it('does not bring a dismissed write back on a late refusal', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(note('a'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $write = $outbox->head() ?? throw new LogicException('expected a');
    $outbox->refused($write, 'validation_failed');
    $outbox->dismiss($write->id);

    $outbox->refused($write, 'validation_failed');

    expect($outbox->abandoned())->toBe([]);
})->with(outboxStores());

/** A record created again under its id, accepted by a server that kept the id, left everything waiting on its refused first create blocked. */
it('records a create accepted under its own id, so nothing waits on an earlier refusal', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'bad')], 0);
    $outbox->refused($outbox->head('projects') ?? throw new LogicException('expected the create'), 'validation_failed');
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'good')], 0);
    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected the new create'));

    expect($outbox->orphanReason('projects', 'p'))->toBeNull()
        ->and(fn () => $outbox->requeue($outbox->abandoned()[0]['mutation']->id))->toThrow(InvalidRequest::class);
})->with(outboxStores());

/** An edit queued before its record's create was sent first, and refused as entity_not_found. */
it('finds a record\'s create wherever it sits in the queue', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Update, [Op::set('t', 'edit')], 0);
    $create = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'new')], 0);

    expect($outbox->queuedCreate('projects', 'p')?->id)->toBe($create->id);
})->with(outboxStores());

/** Naming one create renamed another queued for the same handle - a record of its own - and its refusal then blocked the live record. */
it('leaves another queued create alone when one for the same handle is named', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'first')], 0);
    $second = $outbox->queue(new EntityKey('team-1', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'second')], 0);
    $outbox->acknowledged($outbox->head('projects') ?? throw new LogicException('expected the create'), new EntityKey('team-1', 'projects', 'n'));

    expect($outbox->head('projects')?->id)->toBe($second->id)
        ->and($outbox->head('projects')?->entity->id)->toBe('p');
})->with(outboxStores());

/** Finding a record's create decoded every queued write of it, on every write sent: a long drain of edits went quadratic. */
it('finds a record\'s create without reading its whole queue', function () {
    $timed = function (int $queued): float {
        $store = new PdoOutboxStore(new PDO('sqlite::memory:'));
        $store->migrate();
        $outbox = outboxFor($store);
        foreach (range(1, $queued) as $n) {
            $outbox->queue(new EntityKey('team-1', 'notes', 'other-'.$n), MutationKind::Update, [Op::set('t', (string) $n)], 1);
        }
        $outbox->queue(note('busy'), MutationKind::Update, [Op::set('t', 'x')], 1);
        $started = hrtime(true);
        foreach (range(1, 300) as $ignored) {
            $outbox->queuedCreate('notes', 'busy', 'team-1');
        }

        return (hrtime(true) - $started) / 1e6;
    };

    // The cost of a lookup must not grow with the queue: four times the queue,
    // not four times the time.
    $small = $timed(1000);
    $large = $timed(4000);

    expect($large)->toBeLessThan(max($small * 2.5, 5.0));
});

/**
 * A handle names one record on this device. The same handle for records in two
 * spaces made a reference - which carries no space - point at either, and let a
 * refusal in one space hold back or release the other's writes.
 */
it('refuses a create whose handle this device already uses in another space', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-a', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'a')], 0);

    expect(fn () => $outbox->queue(new EntityKey('team-b', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'b')], 0))
        ->toThrow(InvalidRequest::class, 'another space');

    // In its own space it may be created again - after a refusal, say.
    $outbox->queue(new EntityKey('team-a', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'again')], 0);
    expect($outbox->pending())->toBe(2);
})->with(outboxStores());

/**
 * A scope's rename moved only the queued writes, so a refused create stayed
 * under the old label and its handle named records in two spaces: retrying it
 * sent a second create for a record already being created again.
 */
it('moves abandoned writes along when their scope is named', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $refused = $outbox->queue(new EntityKey('P', 'tasks', 'T'), MutationKind::Create, [Op::set('t', 'bad')], 0);
    $outbox->refused($outbox->head('tasks') ?? throw new LogicException('expected T'), 'validation_failed');
    $outbox->queue(new EntityKey('P', 'tasks', 'T'), MutationKind::Create, [Op::set('t', 'good')], 0);

    $outbox->relabel('tasks', 'P', '42');

    expect($store->handleSpaces('tasks', 'T'))->toBe(['42'])
        ->and($outbox->abandoned()[0]['mutation']->entity->space)->toBe('42')
        ->and(fn () => $outbox->requeue($refused->id))->toThrow(InvalidRequest::class, 'queued again');
})->with(outboxStores());

/** One record with many queued edits: its create was looked for among all of them on every write sent. */
it('finds a record\'s create without reading the record\'s own edits', function () {
    $timed = function (int $edits): float {
        $store = new PdoOutboxStore(new PDO('sqlite::memory:'));
        $store->migrate();
        $outbox = outboxFor($store);
        foreach (range(1, $edits) as $n) {
            $outbox->queue(note('busy'), MutationKind::Update, [Op::set('t', (string) $n)], 1);
        }
        $started = hrtime(true);
        foreach (range(1, 300) as $ignored) {
            $outbox->queuedCreate('notes', 'busy', 'team-1');
        }

        return (hrtime(true) - $started) / 1e6;
    };

    $small = $timed(1000);
    $large = $timed(4000);

    expect($large)->toBeLessThan(max($small * 2.5, 5.0));
});

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

/**
 * A device restored from an old backup, or a new install reusing its id, is
 * BEHIND the server: every write it still has queued may be one it sent
 * before. They are settled together, and new writes go out after where the
 * server is - burning one position per write left it unable to write anything
 * new until it had crawled through the whole pruned range.
 */
it('settles a restored device\'s whole queue at once and goes on after the server', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    foreach (['a', 'b', 'c'] as $id) {
        $outbox->queue(note($id), MutationKind::Update, [Op::set('t', $id)], 1);
    }

    $first = $outbox->head() ?? throw new LogicException('expected a head');
    $outbox->settledUnknown($first, serverAcknowledged: 500);
    $outbox->queue(note('d'), MutationKind::Update, [Op::set('t', 'd')], 1);
    $next = $outbox->head() ?? throw new LogicException('expected the new write');

    expect($outbox->abandoned())->toHaveCount(3)
        ->and(array_unique(array_column($outbox->abandoned(), 'reason')))->toBe(['receipt_pruned'])
        ->and($next->operations[0]->value->value())->toBe('d')
        ->and($next->sequence->value)->toBe(501);
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
