<?php

declare(strict_types=1);

use Cbox\Sync\Client\Pdo\PdoClientState;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Tests\Fixtures\ViewScenario;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\MultiViewClient;
use Cbox\Sync\Views\ViewSyncService;

/** The property a device actually needs: what it knew survives being killed. */
it('comes back from a restart knowing what it already synced', function () {
    $database = tempnam(sys_get_temp_dir(), 'cbox-client-').'.sqlite';

    try {
        $open = function () use ($database): PdoClientState {
            $state = new PdoClientState(new PDO('sqlite:'.$database));
            $state->migrate();

            return $state;
        };

        $scenario = new ViewScenario;
        foreach (['a', 'b'] as $id) {
            $scenario->create($id, 'alpha');
        }
        $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
        $sync = new ViewSyncService($scenario->store, 'v1', 'epoch-1');
        $context = $sync->context('test', $view);

        // First run: bootstrap, then the process ends.
        $before = new MultiViewClient($open());
        $before->applyBootstrap($sync->bootstrap($context, $view, $sync->openBootstrap($context, $view, 10)));
        $cursor = $before->cursor($context);
        expect($cursor)->not->toBeNull();

        // Second run: a brand new client over the same local database.
        $after = new MultiViewClient($open());
        expect($after->record(new EntityKey('test', 'items', 'a'))?->value('title')->value())->toBe('title-a');
        expect($after->cursor($context)?->position->value)->toBe($cursor?->position->value);
        expect($after->belongsTo(new EntityKey('test', 'items', 'a'), 'by-project'))->toBeTrue();

        // And it can carry on from its stored cursor rather than bootstrapping again.
        $scenario->update(new EntityKey('test', 'items', 'a'), [Op::set('title', 'renamed')]);
        $after->applyDelta($sync->delta($after->cursor($context) ?? throw new LogicException('cursor expected'), $view));

        expect($after->record(new EntityKey('test', 'items', 'a'))?->value('title')->value())->toBe('renamed');
    } finally {
        @unlink($database);
    }
});

it('keeps the tombstone watermark after a restart so an old page cannot resurrect a record', function () {
    $database = tempnam(sys_get_temp_dir(), 'cbox-client-').'.sqlite';

    try {
        $open = function () use ($database): PdoClientState {
            $state = new PdoClientState(new PDO('sqlite:'.$database));
            $state->migrate();

            return $state;
        };

        $scenario = new ViewScenario;
        $entity = $scenario->create('a', 'alpha');
        $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
        $sync = new ViewSyncService($scenario->store, 'v1', 'epoch-1');
        $context = $sync->context('test', $view);

        $client = new MultiViewClient($open());
        $client->applyBootstrap($sync->bootstrap($context, $view, $sync->openBootstrap($context, $view, 10)));
        $stale = $sync->openBootstrap($context, $view, 10);

        $scenario->delete($entity);
        $client->applyDelta($sync->delta($client->cursor($context) ?? throw new LogicException('cursor expected'), $view));
        expect($client->record($entity))->toBeNull();

        // A new process, and a bootstrap page opened before the delete.
        $restarted = new MultiViewClient($open());
        $restarted->resetView($context);
        $restarted->applyBootstrap($sync->bootstrap($context, $view, $stale));

        // The watermark survived the restart and the reset, so the record
        // stays gone rather than coming back from a page taken before it died.
        expect($restarted->record($entity))->toBeNull();
    } finally {
        @unlink($database);
    }
});
