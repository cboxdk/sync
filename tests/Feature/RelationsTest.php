<?php

declare(strict_types=1);

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\MultiViewClient;
use Cbox\Sync\Views\ViewSyncService;

/**
 * The engine has no concept of a relation. An entity referring to another is
 * just a field holding an id, and nothing enforces that the other end exists.
 * These pin what that costs, because a host that assumes otherwise will build
 * on a guarantee that is not there.
 */
function relations(): object
{
    $store = new InMemoryStore;

    return new class($store, new Engine($store))
    {
        public function __construct(public InMemoryStore $store, public Engine $engine) {}

        /** @param list<Op> $operations */
        public function write(string $replica, int $sequence, string $id, array $operations, int $base, MutationKind $kind = MutationKind::Update, ?string $dependsOn = null): MutationResult
        {
            return $this->engine->process(new Mutation(
                $replica.'-'.$sequence, new EntityKey('space', 'nodes', $id), new Replica($replica),
                new MutationSequence($sequence), $kind, new RecordVersion($base), $operations, true, $dependsOn,
            ));
        }

        public function record(string $id): ?EntityRecord
        {
            return $this->store->record(new EntityKey('space', 'nodes', $id));
        }
    };
}

it('delivers an offline parent and child in order through a write chain', function () {
    $world = relations();

    // Both created offline, child second, chained to the parent's mutation.
    $parent = $world->write('device', 1, 'parent-1', [Op::set('name', 'Project')], 0, MutationKind::Create);
    expect($parent->status)->toBe(MutationStatus::Applied);

    $child = $world->write('device', 2, 'child-1', [Op::set('parent_id', 'parent-1'), Op::set('name', 'Task')], 0, MutationKind::Create);

    expect($child->status)->toBe(MutationStatus::Applied);
    expect($world->record('child-1')?->value('parent_id')->value())->toBe('parent-1');
});

it('leaves a dangling reference when the parent is deleted', function () {
    $world = relations();
    $world->write('device', 1, 'parent-1', [Op::set('name', 'Project')], 0, MutationKind::Create);
    $world->write('device', 2, 'child-1', [Op::set('parent_id', 'parent-1')], 0, MutationKind::Create);

    $world->write('device', 3, 'parent-1', [], 1, MutationKind::Delete);

    // No cascade, no refusal, no warning. The child still points at the parent,
    // and the application is the only thing that can notice.
    expect($world->record('child-1')?->value('parent_id')->value())->toBe('parent-1');

    // And the parent still resolves: a delete leaves a tombstone, not a hole.
    // Looking a reference up therefore succeeds, and only `deleted` says
    // otherwise - so following one without checking reads a dead record.
    $parent = $world->record('parent-1');
    expect($parent)->not->toBeNull();
    expect($parent?->deleted)->toBeTrue();
});

it('refuses a second create of the same entity rather than overwriting it', function () {
    $world = relations();
    $world->write('a', 1, 'node-1', [Op::set('name', 'first')], 0, MutationKind::Create);

    $second = $world->write('b', 1, 'node-1', [Op::set('name', 'second')], 0, MutationKind::Create);

    expect($second->status)->toBe(MutationStatus::Rejected);
    expect($second->reason)->toBe('entity_exists');
    expect($world->record('node-1')?->value('name')->value())->toBe('first');
});

it('never lets a deleted entity be recreated under the same id', function () {
    $world = relations();
    $world->write('a', 1, 'node-1', [Op::set('name', 'first')], 0, MutationKind::Create);
    $world->write('a', 2, 'node-1', [], 1, MutationKind::Delete);

    $recreate = $world->write('a', 3, 'node-1', [Op::set('name', 'again')], 0, MutationKind::Create);

    // The tombstone is permanent: an id is spent. A client that reuses one
    // silently gets a rejection, so ids must be generated, never derived.
    expect($recreate->status)->toBe(MutationStatus::Rejected);
    expect($recreate->reason)->toBe('entity_exists');
});

it('moves a child between two parents as a removal from one view and an entry into the other', function () {
    $world = relations();
    $world->write('device', 1, 'child-1', [Op::set('parent_id', 'parent-a'), Op::set('name', 'Task')], 0, MutationKind::Create);

    $inA = FieldEqualsView::matching('under-a', '1', 'parent_id', 'parent-a', 'nodes');
    $inB = FieldEqualsView::matching('under-b', '1', 'parent_id', 'parent-b', 'nodes');
    $sync = new ViewSyncService($world->store, 'v1', 'epoch-1');
    $contextA = $sync->context('space', $inA);
    $contextB = $sync->context('space', $inB);

    $client = new MultiViewClient;
    $client->applyBootstrap($sync->bootstrap($contextA, $inA, $sync->openBootstrap($contextA, $inA, 10)));
    $client->applyBootstrap($sync->bootstrap($contextB, $inB, $sync->openBootstrap($contextB, $inB, 10)));
    $cursorA = $client->cursor($contextA) ?? throw new LogicException('cursor expected');
    $cursorB = $client->cursor($contextB) ?? throw new LogicException('cursor expected');

    expect($client->belongsTo(new EntityKey('space', 'nodes', 'child-1'), 'under-a'))->toBeTrue();
    expect($client->belongsTo(new EntityKey('space', 'nodes', 'child-1'), 'under-b'))->toBeFalse();

    // Reparent. One field changes; two views see completely different events.
    $world->write('device', 2, 'child-1', [Op::set('parent_id', 'parent-b')], 1);

    $deltaA = $sync->delta($cursorA, $inA);
    $deltaB = $sync->delta($cursorB, $inB);

    expect($deltaA->commits[0]->changes[0]->kind->value)->toBe('removed_from_scope');
    expect($deltaB->commits[0]->changes[0]->kind->value)->toBe('upsert');

    $client->applyDelta($deltaA);
    $client->applyDelta($deltaB);

    // The record survives the removal because another view still owns it. A
    // client that dropped it on removed_from_scope would lose data it is
    // entitled to.
    expect($client->record(new EntityKey('space', 'nodes', 'child-1')))->not->toBeNull();
    expect($client->belongsTo(new EntityKey('space', 'nodes', 'child-1'), 'under-a'))->toBeFalse();
    expect($client->belongsTo(new EntityKey('space', 'nodes', 'child-1'), 'under-b'))->toBeTrue();
});

it('drops a record only when the last view that owned it lets go', function () {
    $world = relations();
    $world->write('device', 1, 'child-1', [Op::set('parent_id', 'parent-a'), Op::set('kind', 'task')], 0, MutationKind::Create);

    $byParent = FieldEqualsView::matching('under-a', '1', 'parent_id', 'parent-a', 'nodes');
    $byKind = FieldEqualsView::matching('tasks', '1', 'kind', 'task', 'nodes');
    $sync = new ViewSyncService($world->store, 'v1', 'epoch-1');
    $parentContext = $sync->context('space', $byParent);
    $kindContext = $sync->context('space', $byKind);

    $client = new MultiViewClient;
    $client->applyBootstrap($sync->bootstrap($parentContext, $byParent, $sync->openBootstrap($parentContext, $byParent, 10)));
    $client->applyBootstrap($sync->bootstrap($kindContext, $byKind, $sync->openBootstrap($kindContext, $byKind, 10)));
    $parentCursor = $client->cursor($parentContext) ?? throw new LogicException('cursor expected');
    $kindCursor = $client->cursor($kindContext) ?? throw new LogicException('cursor expected');

    $entity = new EntityKey('space', 'nodes', 'child-1');

    // Leaves the parent view but stays a task.
    $world->write('device', 2, 'child-1', [Op::set('parent_id', 'parent-b')], 1);
    $client->applyDelta($sync->delta($parentCursor, $byParent));
    expect($client->record($entity))->not->toBeNull();

    // Now it stops being a task too, and nothing owns it.
    $world->write('device', 3, 'child-1', [Op::set('kind', 'note')], 2);
    $client->applyDelta($sync->delta($kindCursor, $byKind));
    expect($client->record($entity))->toBeNull();
});
