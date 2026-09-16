<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Tests\Fixtures\ViewScenario;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\CursorContext;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\MultiViewClient;
use Cbox\Sync\Views\ResetReason;
use Cbox\Sync\Views\ResetRequired;
use Cbox\Sync\Views\ViewChangeKind;
use Cbox\Sync\Views\ViewCursor;
use Cbox\Sync\Views\ViewSyncService;

it('converges from a frozen paginated bootstrap through concurrent membership and data changes', function () {
    $scenario = new ViewScenario;
    $a = $scenario->create('a', 'alpha');
    $b = $scenario->create('b', 'alpha');
    $c = $scenario->create('c', 'alpha');
    $d = $scenario->create('d', 'beta');
    $view = FieldEqualsView::matching('project-alpha', 'v1', 'project', 'alpha', 'items');
    $sync = new ViewSyncService($scenario->store, 'schema-1', 'epoch-1');
    $context = $sync->context('test', $view);
    $token = $sync->openBootstrap($context, $view, 1);
    $page = $sync->bootstrap($context, $view, $token);
    $retry = $sync->bootstrap($context, $view, $token);
    expect(serialize($retry))->toBe(serialize($page));
    expect($page->records)->toHaveCount(1)
        ->and($page->records[0]->entity->id)->toBe('a')
        ->and($page->cursor)->toBeNull()
        ->and($page->nextToken)->not->toBeNull();

    $client = new MultiViewClient;
    expect($page->context->fingerprint())->toBe($sync->context('test', $view)->fingerprint());
    $client->applyBootstrap($page);
    $e = $scenario->create('e', 'alpha', actor: 'actor-new');
    $scenario->update($b, [Op::set('title', 'updated-b')], 'actor-update');
    $scenario->delete($c, 'actor-delete');
    $scenario->update($d, [Op::set('project', 'alpha')], 'actor-enter');
    $scenario->update($a, [Op::set('project', 'beta')], 'actor-leave');

    while ($page->nextToken !== null) {
        $page = $sync->bootstrap($context, $view, $page->nextToken);
        $client->applyBootstrap($page);
    }
    expect($page->isComplete())->toBeTrue()
        ->and($page->cursor)->not->toBeNull();

    $cursor = $page->cursor ?? throw new LogicException('Bootstrap did not return a cursor');
    $kinds = [];
    $origins = [];
    do {
        $delta = $sync->delta($cursor, $view, 2);
        $sameDelta = $sync->delta($cursor, $view, 2);
        expect(serialize($sameDelta))->toBe(serialize($delta))
            ->and($delta->cursor->position->value - $cursor->position->value)->toBeLessThanOrEqual(2);
        foreach ($delta->commits as $commit) {
            foreach ($commit->changes as $change) {
                $kinds[] = $change->kind;
                $origins[] = $change->provenance?->actorId;
            }
        }
        $client->applyDelta($delta);
        $cursor = $delta->cursor;
    } while ($delta->hasMore);

    expect($client->record($a))->toBeNull()
        ->and($client->record($b)?->value('title')->value())->toBe('updated-b')
        ->and($client->record($c))->toBeNull()
        ->and($client->record($d))->not->toBeNull()
        ->and($client->record($e))->not->toBeNull()
        ->and($kinds)->toContain(ViewChangeKind::Upsert, ViewChangeKind::Deleted, ViewChangeKind::RemovedFromScope)
        ->and($origins)->toContain('actor-new', 'actor-update', 'actor-delete', 'actor-enter', 'actor-leave');
});

it('distinguishes a global tombstone from removal and emits a full record on view entry', function () {
    $scenario = new ViewScenario;
    $removed = $scenario->create('removed', 'alpha');
    $deleted = $scenario->create('deleted', 'alpha');
    $entering = $scenario->create('entering', 'beta');
    $view = FieldEqualsView::matching('alpha', 'v1', 'project', 'alpha');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $first = $sync->bootstrap($sync->context('test', $view), $view, $sync->openBootstrap($sync->context('test', $view), $view));
    $cursor = $first->cursor ?? throw new LogicException('Single page bootstrap expected');

    $scenario->update($removed, [Op::set('project', 'beta')]);
    $scenario->delete($deleted);
    $scenario->update($entering, [Op::set('project', 'alpha')]);
    $delta = $sync->delta($cursor, $view);
    $changes = array_merge(...array_map(fn ($commit) => $commit->changes, $delta->commits));

    expect($changes)->toHaveCount(3)
        ->and($changes[0]->kind)->toBe(ViewChangeKind::RemovedFromScope)
        ->and($changes[0]->record)->toBeNull()
        ->and($changes[1]->kind)->toBe(ViewChangeKind::Deleted)
        ->and($changes[1]->record)->toBeNull()
        ->and($changes[2]->kind)->toBe(ViewChangeKind::Upsert)
        ->and($changes[2]->record?->entity->id)->toBe('entering')
        ->and($changes[2]->record?->value('title')->value())->toBe('title-entering');
});

it('binds cursors to actual filters schema and epoch and gives typed reset reasons', function () {
    $scenario = new ViewScenario;
    $alpha = FieldEqualsView::matching('project', 'v1', 'project', 'alpha');
    $betaWithSameIdentity = FieldEqualsView::matching('project', 'v1', 'project', 'beta');
    $sync = new ViewSyncService($scenario->store, 'schema-1', 'epoch-1');
    $cursor = new ViewCursor($sync->context('test', $alpha));

    try {
        $sync->delta($cursor, $betaWithSameIdentity);
        test()->fail('Changed filter signature was accepted');
    } catch (ResetRequired $exception) {
        expect($exception->reason)->toBe(ResetReason::ContextChanged);
    }

    $oldEpoch = new CursorContext('test', $alpha->id(), $alpha->filterVersion(), $alpha->filterSignature(), 'schema-1', 'old');
    $wrongSchema = new CursorContext('test', $alpha->id(), $alpha->filterVersion(), $alpha->filterSignature(), 'schema-2', 'epoch-1');
    $wrongView = new CursorContext('test', 'other-view', $alpha->filterVersion(), $alpha->filterSignature(), 'schema-1', 'epoch-1');
    $wrongFilterVersion = new CursorContext('test', $alpha->id(), 'v2', $alpha->filterSignature(), 'schema-1', 'epoch-1');
    foreach ([$oldEpoch, $wrongSchema, $wrongView, $wrongFilterVersion] as $mismatch) {
        try {
            $sync->delta(new ViewCursor($mismatch), $alpha);
            test()->fail('Mismatched cursor context was accepted');
        } catch (ResetRequired $exception) {
            expect($exception->reason)->toBe(ResetReason::ContextChanged);
        }
    }
    expect(fn () => $sync->delta(new ViewCursor($sync->context('test', $alpha), new CommitSequence(1)), $alpha))->toThrow(ResetRequired::class);
    expect(fn () => $sync->bootstrap($sync->context('test', $alpha), $alpha, new BootstrapToken('unknown')))->toThrow(ResetRequired::class);
});

it('advances over filtered source commits without splitting its commit budget', function () {
    $scenario = new ViewScenario;
    $view = FieldEqualsView::matching('alpha', 'v1', 'project', 'alpha');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $cursor = new ViewCursor($sync->context('test', $view));
    $scenario->create('outside-1', 'beta');
    $scenario->create('outside-2', 'beta');
    $scenario->create('inside', 'alpha');

    $first = $sync->delta($cursor, $view, 2);
    expect($first->commits)->toBe([])
        ->and($first->cursor->position->value)->toBe(2)
        ->and($first->hasMore)->toBeTrue();
    $second = $sync->delta($first->cursor, $view, 2);
    expect($second->commits)->toHaveCount(1)
        ->and($second->commits[0]->sourceSequence->value)->toBe(3)
        ->and($second->cursor->position->value)->toBe(3)
        ->and($second->hasMore)->toBeFalse();
});

it('retains an entity removed from one local view while another view still owns membership', function () {
    $scenario = new ViewScenario;
    $entity = $scenario->create('shared', 'alpha', 'open');
    $project = FieldEqualsView::matching('alpha-project', 'v1', 'project', 'alpha');
    $open = FieldEqualsView::matching('open-items', 'v1', 'status', 'open');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $projectBootstrap = $sync->bootstrap($sync->context('test', $project), $project, $sync->openBootstrap($sync->context('test', $project), $project));
    $openBootstrap = $sync->bootstrap($sync->context('test', $open), $open, $sync->openBootstrap($sync->context('test', $open), $open));
    $client = new MultiViewClient;
    $client->applyBootstrap($projectBootstrap);
    $client->applyBootstrap($openBootstrap);

    $scenario->update($entity, [Op::set('project', 'beta')]);
    $projectDelta = $sync->delta($projectBootstrap->cursor ?? throw new LogicException('Cursor expected'), $project);
    $client->applyDelta($projectDelta);
    expect($client->record($entity))->not->toBeNull()
        ->and($client->belongsTo($entity, $project->id()))->toBeFalse()
        ->and($client->belongsTo($entity, $open->id()))->toBeTrue();

    $openDelta = $sync->delta($openBootstrap->cursor ?? throw new LogicException('Cursor expected'), $open);
    $client->applyDelta($openDelta);
    expect($client->record($entity)?->version->value)->toBe(2);
    $scenario->update($entity, [Op::set('status', 'closed')]);
    $nextOpen = $sync->delta($openDelta->cursor, $open);
    $client->applyDelta($nextOpen);
    expect($client->record($entity))->toBeNull();
});

it('keeps the newest canonical record when overlapping views catch up in a different order', function () {
    $scenario = new ViewScenario;
    $entity = $scenario->create('ordered', 'alpha', 'open');
    $project = FieldEqualsView::matching('alpha-project', 'v1', 'project', 'alpha');
    $open = FieldEqualsView::matching('open-items', 'v1', 'status', 'open');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $projectBootstrap = $sync->bootstrap($sync->context('test', $project), $project, $sync->openBootstrap($sync->context('test', $project), $project));
    $openBootstrap = $sync->bootstrap($sync->context('test', $open), $open, $sync->openBootstrap($sync->context('test', $open), $open));
    $client = new MultiViewClient;
    $client->applyBootstrap($projectBootstrap);
    $client->applyBootstrap($openBootstrap);

    $scenario->update($entity, [Op::set('title', 'version-2')]);
    $scenario->update($entity, [Op::set('status', 'closed')]);
    $projectDelta = $sync->delta($projectBootstrap->cursor ?? throw new LogicException('Cursor expected'), $project);
    $openDelta = $sync->delta($openBootstrap->cursor ?? throw new LogicException('Cursor expected'), $open);

    $client->applyDelta($projectDelta);
    expect($client->record($entity)?->version->value)->toBe(3);
    $client->applyDelta($openDelta);

    expect($client->record($entity)?->version->value)->toBe(3)
        ->and($client->record($entity)?->value('status')->value())->toBe('closed')
        ->and($client->belongsTo($entity, $project->id()))->toBeTrue()
        ->and($client->belongsTo($entity, $open->id()))->toBeFalse()
        ->and($client->cursor($openDelta->cursor->context)?->position->value)->toBe($openDelta->cursor->position->value);

    $scenario->delete($entity);
    $delete = $sync->delta($projectDelta->cursor, $project);
    $client->applyDelta($delete);
    $client->applyBootstrap($openBootstrap);
    $openAfterDelete = $sync->delta($openDelta->cursor, $open);
    $client->applyDelta($openAfterDelete);
    expect($openAfterDelete->commits)->toBe([])
        ->and($client->record($entity))->toBeNull();
});

it('registers membership from an older frozen view without regressing canonical data', function () {
    $scenario = new ViewScenario;
    $entity = $scenario->create('frozen-overlap', 'alpha', 'open');
    $project = FieldEqualsView::matching('alpha-project', 'v1', 'project', 'alpha');
    $open = FieldEqualsView::matching('open-items', 'v1', 'status', 'open');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $projectBootstrap = $sync->bootstrap($sync->context('test', $project), $project, $sync->openBootstrap($sync->context('test', $project), $project));
    $openBootstrap = $sync->bootstrap($sync->context('test', $open), $open, $sync->openBootstrap($sync->context('test', $open), $open));
    $client = new MultiViewClient;
    $client->applyBootstrap($projectBootstrap);

    $scenario->update($entity, [Op::set('title', 'version-2')]);
    $projectUpdate = $sync->delta($projectBootstrap->cursor ?? throw new LogicException('Cursor expected'), $project);
    $client->applyDelta($projectUpdate);
    $client->applyBootstrap($openBootstrap);
    expect($client->record($entity)?->version->value)->toBe(2)
        ->and($client->belongsTo($entity, $open->id()))->toBeTrue();

    $scenario->update($entity, [Op::set('project', 'beta')]);
    $projectRemoval = $sync->delta($projectUpdate->cursor, $project);
    $client->applyDelta($projectRemoval);
    expect($client->record($entity))->not->toBeNull()
        ->and($client->belongsTo($entity, $project->id()))->toBeFalse()
        ->and($client->belongsTo($entity, $open->id()))->toBeTrue();

    $openCatchup = $sync->delta($openBootstrap->cursor ?? throw new LogicException('Cursor expected'), $open);
    $client->applyDelta($openCatchup);
    expect($client->record($entity)?->version->value)->toBe(3)
        ->and($client->record($entity)?->value('project')->value())->toBe('beta');
});

it('does not resurrect a tombstone when a slower view delivers an older upsert', function () {
    $scenario = new ViewScenario;
    $entity = $scenario->create('deleted-order', 'alpha', 'open');
    $project = FieldEqualsView::matching('alpha-project', 'v1', 'project', 'alpha');
    $open = FieldEqualsView::matching('open-items', 'v1', 'status', 'open');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $projectBootstrap = $sync->bootstrap($sync->context('test', $project), $project, $sync->openBootstrap($sync->context('test', $project), $project));
    $openBootstrap = $sync->bootstrap($sync->context('test', $open), $open, $sync->openBootstrap($sync->context('test', $open), $open));
    $client = new MultiViewClient;
    $client->applyBootstrap($projectBootstrap);
    $client->applyBootstrap($openBootstrap);

    $scenario->update($entity, [Op::set('title', 'version-2')]);
    $slowOpenPage = $sync->delta($openBootstrap->cursor ?? throw new LogicException('Cursor expected'), $open, 1);
    $scenario->delete($entity);
    $projectDelta = $sync->delta($projectBootstrap->cursor ?? throw new LogicException('Cursor expected'), $project);
    $client->applyDelta($projectDelta);
    expect($client->record($entity))->toBeNull();

    $client->applyDelta($slowOpenPage);
    expect($client->record($entity))->toBeNull()
        ->and($client->belongsTo($entity, $open->id()))->toBeFalse();
});

it('binds client application to page context and atomically deduplicates each view cursor', function () {
    $scenario = new ViewScenario;
    $entity = $scenario->create('cursor-order', 'alpha');
    $view = FieldEqualsView::matching('alpha', 'v1', 'project', 'alpha');
    $other = FieldEqualsView::matching('open', 'v1', 'status', 'open');
    $sync = new ViewSyncService($scenario->store, '1', 'one');
    $bootstrap = $sync->bootstrap($sync->context('test', $view), $view, $sync->openBootstrap($sync->context('test', $view), $view));
    $client = new MultiViewClient;
    $client->applyBootstrap($bootstrap);
    $client->applyBootstrap($bootstrap);
    $otherSpaceBootstrap = $sync->bootstrap($sync->context('other-space', $view), $view, $sync->openBootstrap($sync->context('other-space', $view), $view));
    $client->applyBootstrap($otherSpaceBootstrap);
    expect($client->cursor($bootstrap->context)?->position->value)->toBe(1)
        ->and($client->cursor($otherSpaceBootstrap->context)?->position->value)->toBe(0);

    $scenario->update($entity, [Op::set('title', 'version-2')]);
    $first = $sync->delta($bootstrap->cursor ?? throw new LogicException('Cursor expected'), $view, 1);
    $scenario->update($entity, [Op::set('title', 'version-3')]);
    $second = $sync->delta($first->cursor, $view, 1);

    expect(fn () => $client->applyDelta($second))->toThrow(InvalidRequest::class);
    expect($client->record($entity)?->version->value)->toBe(1)
        ->and($client->cursor($bootstrap->context)?->position->value)->toBe($bootstrap->cursor?->position->value);
    $client->applyDelta($first);
    $client->applyDelta($first);
    $client->applyDelta($second);
    expect($client->record($entity)?->version->value)->toBe(3)
        ->and($client->cursor($bootstrap->context)?->position->value)->toBe($second->cursor->position->value);

    $unrelated = $sync->delta(new ViewCursor($sync->context('test', $other)), $other);
    expect(fn () => $client->applyDelta($unrelated))->toThrow(InvalidRequest::class);
    expect($client->cursor($bootstrap->context)?->position->value)->toBe($second->cursor->position->value);
});
