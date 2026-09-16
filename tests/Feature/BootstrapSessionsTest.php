<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Tests\Fixtures\ViewScenario;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\Views\BootstrapPage;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\KeysetBootstrapSessions;
use Cbox\Sync\Views\ResetReason;
use Cbox\Sync\Views\ResetRequired;
use Cbox\Sync\Views\ViewSyncService;

/**
 * The keyset strategy is what a multi-process deployment needs: nothing is kept
 * between requests, so any worker can serve any page.
 */
function keysetSync(ViewScenario $scenario): ViewSyncService
{
    // A fresh sessions object per call, so nothing can be carried in memory.
    return new ViewSyncService($scenario->store, 'v1', 'epoch-1', new KeysetBootstrapSessions($scenario->store, 'token-secret'));
}

it('pages a bootstrap across processes that share no memory', function () {
    $scenario = new ViewScenario;
    foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
        $scenario->create($id, 'alpha');
    }
    $scenario->create('f', 'beta');

    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
    $watermark = $scenario->store->watermark('test');

    $context = keysetSync($scenario)->context('test', $view);
    $token = keysetSync($scenario)->openBootstrap($context, $view, 2);

    $seen = [];
    $pages = 0;
    $page = null;
    while ($token !== null) {
        $page = keysetSync($scenario)->bootstrap($context, $view, $token);
        expect($page)->toBeInstanceOf(BootstrapPage::class);
        foreach ($page->records as $record) {
            $seen[] = $record->entity->id;
        }
        $token = $page->nextToken;
        $pages++;
    }

    expect($seen)->toBe(['a', 'b', 'c', 'd', 'e']);
    expect($pages)->toBe(3);
    expect($page?->isComplete())->toBeTrue();
    expect($page?->cursor?->position->value)->toBe($watermark->value);
});

it('refuses a tampered or unsigned bootstrap token', function () {
    $scenario = new ViewScenario;
    $scenario->create('a', 'alpha');
    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
    $sync = keysetSync($scenario);
    $token = $sync->openBootstrap($sync->context('test', $view), $view, 1);

    [$payload, $signature] = explode('.', $token->value);
    $forged = new BootstrapToken(rtrim(strtr(base64_encode(str_replace('"pageSize":1', '"pageSize":9', (string) base64_decode(strtr($payload, '-_', '+/'), true))), '+/', '-_'), '=').'.'.$signature);

    expect(fn () => keysetSync($scenario)->bootstrap($sync->context('test', $view), $view, $forged))->toThrow(ResetRequired::class);
    expect(fn () => keysetSync($scenario)->bootstrap($sync->context('test', $view), $view, new BootstrapToken('not-a-token')))->toThrow(ResetRequired::class);
});

it('refuses a token presented with a different view', function () {
    $scenario = new ViewScenario;
    $scenario->create('a', 'alpha');
    $alpha = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
    $beta = FieldEqualsView::matching('by-project', '1', 'project', 'beta', 'items');
    $sync = keysetSync($scenario);
    $token = $sync->openBootstrap($sync->context('test', $alpha), $alpha, 1);

    try {
        keysetSync($scenario)->bootstrap(keysetSync($scenario)->context('test', $beta), $beta, $token);
        throw new LogicException('Expected a reset');
    } catch (ResetRequired $reset) {
        expect($reset->reason)->toBe(ResetReason::ContextChanged);
    }
});

it('converges when the view changes underneath an open bootstrap', function () {
    $scenario = new ViewScenario;
    foreach (['a', 'b', 'c'] as $id) {
        $scenario->create($id, 'alpha');
    }
    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
    $sync = keysetSync($scenario);
    $context = $sync->context('test', $view);
    $token = $sync->openBootstrap($context, $view, 1);

    $first = keysetSync($scenario)->bootstrap($context, $view, $token);
    expect(array_map(fn ($record): string => $record->entity->id, $first->records))->toBe(['a']);

    // 'b' leaves the view and a new member appears while the bootstrap is open.
    $scenario->update(new EntityKey('test', 'items', 'b'), [FieldOperation::set('project', 'beta')]);
    $scenario->create('g', 'alpha');

    $token = $first->nextToken;
    $seen = ['a'];
    $page = $first;
    while ($token !== null) {
        $page = keysetSync($scenario)->bootstrap($context, $view, $token);
        foreach ($page->records as $record) {
            $seen[] = $record->entity->id;
        }
        $token = $page->nextToken;
    }

    // 'b' is gone from the view and is never delivered; 'g' sorts after 'c' and is.
    expect($seen)->toBe(['a', 'c', 'g']);
    expect($page->cursor)->not->toBeNull();
});
