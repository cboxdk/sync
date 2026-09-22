<?php

declare(strict_types=1);

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;

/** R14-A: another space's create of the same handle, accepted under its own id, stops dismiss() cascading. */
it('R14-A dismiss still takes along the edits of a create refused in space A when space B created the same handle', function (OutboxStore $store) {
    $outbox = outboxFor($store)->relatedBy(['tasks' => ['project_id' => 'projects']], []);
    // Space B: project p created, server keeps the id.
    $outbox->queue(new EntityKey('team-b', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'b')], 0);
    $outbox->acknowledged($outbox->head('projects', 'team-b') ?? throw new LogicException('b'));
    // Space A: project p created and refused; a child points at it.
    $outbox->queue(new EntityKey('team-a', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $child = $outbox->queue(new EntityKey('team-a', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);
    $create = $outbox->head('projects', 'team-a') ?? throw new LogicException('a');
    $outbox->refused($create, 'validation_failed');

    expect($outbox->orphanReason('projects', 'p'))->toBe('parent_abandoned');
    $cascaded = $outbox->dismiss($create->id);

    // The child must not go out pointing at team-a/p, which was never created.
    expect($cascaded)->toBe(1)
        ->and($outbox->head('tasks', 'team-a'))->toBeNull();
})->with(outboxStores());

/** R14-B: requeue of a create refused in space A is refused because space B has a record of the same handle. */
it('R14-B requeues a create refused in space A although space B created the same handle', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-b', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'b')], 0);
    $outbox->acknowledged($outbox->head('projects', 'team-b') ?? throw new LogicException('b'));
    $outbox->queue(new EntityKey('team-a', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $create = $outbox->head('projects', 'team-a') ?? throw new LogicException('a');
    $outbox->refused($create, 'rejected');

    expect($outbox->requeue($create->id))->not->toBeNull();
})->with(outboxStores());

/** R14-C: a reference to a handle named in space A is no longer rewritten once space B kept the same handle. */
it('R14-C rewrites a later child\'s reference to a parent named in space A when space B kept the same handle', function (OutboxStore $store) {
    $outbox = outboxFor($store);
    $outbox->queue(new EntityKey('team-a', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'a')], 0);
    $outbox->acknowledged($outbox->head('projects', 'team-a') ?? throw new LogicException('a'), new EntityKey('team-a', 'projects', 'X'));
    $outbox->queue(new EntityKey('team-b', 'projects', 'p'), MutationKind::Create, [Op::set('t', 'b')], 0);
    $outbox->acknowledged($outbox->head('projects', 'team-b') ?? throw new LogicException('b'));
    $child = $outbox->queue(new EntityKey('team-a', 'tasks', 't'), MutationKind::Create, [Op::set('project_id', 'p')], 0);

    $outbox->mapNames($child, ['tasks' => ['project_id' => 'projects']], []);

    expect($outbox->head('tasks')?->operations[0]->value->value())->toBe('X');
})->with(outboxStores());

/** R14-P: randomized parity of every store against the in-memory one. */
it('R14-P behaves identically on every store', function () {
    $stores = outboxStores();
    $spaces = ['s1', 's2'];
    $types = ['projects', 'tasks'];
    $ids = ['a', 'b', 'c'];
    $refs = ['tasks' => ['project_id' => 'projects']];
    $scoped = [];
    $mismatches = [];
    for ($seed = 1; $seed <= 60; $seed++) {
        $traces = [];
        foreach ($stores as $name => $make) {
            mt_srand($seed);
            $store = $make();
            $outbox = outboxFor($store)->relatedBy($refs, $scoped);
            $trace = [];
            for ($step = 0; $step < 40; $step++) {
                $op = mt_rand(0, 9);
                $space = $spaces[mt_rand(0, 1)];
                $type = $types[mt_rand(0, 1)];
                $id = $ids[mt_rand(0, 2)];
                $pick = mt_rand(0, 100);
                $rename = mt_rand(0, 2);
                try {
                    $result = match (true) {
                        $op <= 2 => $outbox->queue(new EntityKey($space, $type, $id), $op === 0 ? MutationKind::Update : MutationKind::Create, [Op::set('project_id', $ids[$pick % 3])], 0)->id,
                        $op === 3 => (function () use ($outbox, $space, $rename, $refs) {
                            $m = $outbox->peek(null, $space);
                            if ($m === null) {
                                return 'none';
                            }
                            $outbox->mapNames($m, $refs, []);
                            $h = $outbox->head(null, $space);
                            if ($h === null) {
                                return 'none';
                            }
                            $named = $h->kind === MutationKind::Create && $rename === 0 ? new EntityKey($h->entity->space, $h->entity->type, 'N'.$h->id) : null;
                            $outbox->acknowledged($h, $named, $refs);

                            return 'ack '.$h->id.' '.$h->entity->key().' '.json_encode($h->operations);
                        })(),
                        $op === 4 => (function () use ($outbox, $space, $rename) {
                            $h = $outbox->head(null, $space);
                            if ($h === null) {
                                return 'none';
                            }
                            $outbox->refused($h, $rename === 0 ? 'rejected' : 'validation_failed');

                            return 'refused '.$h->id;
                        })(),
                        $op === 5 => (function () use ($outbox, $pick) {
                            $a = $outbox->abandoned(); usort($a, fn ($x, $y) => strnatcmp($x["mutation"]->id, $y["mutation"]->id));

                            return $a === [] ? 'none' : 'dismiss '.$outbox->dismiss($a[$pick % count($a)]['mutation']->id);
                        })(),
                        $op === 6 => (function () use ($outbox, $pick) {
                            $a = $outbox->abandoned(); usort($a, fn ($x, $y) => strnatcmp($x["mutation"]->id, $y["mutation"]->id));

                            return $a === [] ? 'none' : 'requeue '.$outbox->requeue($a[$pick % count($a)]['mutation']->id)?->entity->key();
                        })(),
                        $op === 7 => (function () use ($outbox, $space, $type, $id) {
                            $outbox->found(new EntityKey($space, $type, $id), 'F'.$id);

                            return 'found';
                        })(),
                        $op === 8 => (function () use ($outbox, $space) {
                            $h = $outbox->head(null, $space);
                            if ($h === null) {
                                return 'none';
                            }
                            $outbox->abandon($h, 'gave_up', answered: false);

                            return 'gaveup '.$h->id;
                        })(),
                        default => 'noop',
                    };
                } catch (Throwable $e) {
                    $result = get_class($e).': '.$e->getMessage();
                }
                $state = [];
                foreach ($types as $t) {
                    foreach ($ids as $i) {
                        $state[] = [$t, $i, $outbox->queuedCreate($t, $i)?->id, $outbox->orphanReason($t, $i), $store->namedAs($t, $i), $store->abandonedCreate($t, $i)['mutation']->id ?? null];
                        foreach ($spaces as $s) {
                            $state[] = [$s, $t, $i, $outbox->nameOf(new EntityKey($s, $t, $i))?->id, $store->abandonedCreate($t, $i, $s)['mutation']->id ?? null];
                        }
                    }
                }
                $trace[] = [$result, $outbox->pending(), (function () use ($outbox) { $l = array_map(fn ($e) => [$e["mutation"]->id, $e["mutation"]->entity->key(), $e["reason"]], $outbox->abandoned()); sort($l); return $l; })(), $state];
            }
            $traces[$name] = $trace;
        }
        foreach ($traces as $name => $trace) {
            if ($name === 'memory') {
                continue;
            }
            foreach ($trace as $step => $row) {
                if ($row !== $traces['memory'][$step]) {
                    $mismatches[] = "seed $seed step $step $name: ".json_encode($row).' vs memory '.json_encode($traces['memory'][$step]);
                    break;
                }
            }
        }
    }
    expect($mismatches)->toBe([]);
});
