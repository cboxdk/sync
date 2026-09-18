<?php

declare(strict_types=1);

use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Tests\Fixtures\ViewScenario;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\Views\EntityTypeView;
use Cbox\Sync\Views\ViewCursor;
use Cbox\Sync\Views\ViewSyncService;

it('holds every live record of its type and nothing of another', function () {
    $scenario = new ViewScenario($this->syncStore());
    $scenario->create('a', 'alpha');
    $scenario->create('b', 'beta');
    $gone = $scenario->create('c', 'alpha');
    $scenario->create('n1', 'alpha', type: 'notes');
    $scenario->delete($gone);

    $view = EntityTypeView::of('items');
    $sync = new ViewSyncService($scenario->store, 'schema-1', 'epoch-1');
    $context = $sync->context('test', $view);

    $token = $sync->openBootstrap($context, $view, 50);
    $page = $sync->bootstrap($context, $view, $token);

    $ids = array_map(fn ($record) => $record->entity->id, $page->records);
    sort($ids);

    // Both projects, because the space is the boundary and this view adds no
    // filter. Not the tombstone, and not the other entity type.
    expect($ids)->toBe(['a', 'b']);
});

it('is queryable, so a delta skips another type without decoding it', function () {
    $store = new class extends InMemoryStore
    {
        public int $commitsRead = 0;

        public function commitsAfter(string $space, int $after, int $limit, ?string $entityType = null): array
        {
            $commits = parent::commitsAfter($space, $after, $limit, $entityType);
            $this->commitsRead += count($commits);

            return $commits;
        }
    };

    $scenario = new ViewScenario($store);
    $scenario->create('a', 'alpha');
    $scenario->create('n1', 'alpha', type: 'notes');
    $scenario->create('n2', 'alpha', type: 'notes');

    $view = EntityTypeView::of('items');
    $sync = new ViewSyncService($store, 'schema-1', 'epoch-1');
    $sync->delta(new ViewCursor($sync->context('test', $view), new CommitSequence(0)), $view);

    expect($store->commitsRead)->toBe(1);
});

it('refuses an empty identity', function () {
    expect(fn () => EntityTypeView::of(''))->toThrow(InvalidRequest::class);
});

/** The version is the lever a host pulls to make every device rebuild the view. */
it('changes its signature only when the type changes, and its version independently', function () {
    expect(EntityTypeView::of('items')->filterSignature())
        ->toBe(EntityTypeView::of('items', 'v9')->filterSignature());

    expect(EntityTypeView::of('items')->filterVersion())
        ->not->toBe(EntityTypeView::of('items', 'v9')->filterVersion());

    expect(EntityTypeView::of('items')->filterSignature())
        ->not->toBe(EntityTypeView::of('notes')->filterSignature());
});
