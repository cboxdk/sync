<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\KeysetBootstrapSessions;
use Cbox\Sync\Views\ResetReason;
use Cbox\Sync\Views\ResetRequired;
use Cbox\Sync\Views\ViewSyncService;

/**
 * A bootstrap token names the space it will read. Serving a page on the token
 * alone makes it a bearer capability for that space: anyone who obtains one
 * reads the tenant it was issued for, and an epoch rotation - the one tool for
 * forcing every client to reset - cannot revoke it.
 *
 * The caller must therefore assert the context it expects, exactly as delta()
 * already does through its cursor.
 */
function seedSpace(Engine $engine, string $space, string $id, string $secret): void
{
    $engine->process(new Mutation(
        $space.'-'.$id, new EntityKey($space, 'notes', $id), new Replica('r-'.$space.'-'.$id),
        new MutationSequence(1), MutationKind::Create, new RecordVersion(0),
        [Op::set('project', 'alpha'), Op::set('secret', $secret)],
    ));
}

it('refuses a bootstrap token issued for another space', function () {
    $store = new InMemoryStore;
    $engine = new Engine($store);
    seedSpace($engine, 'tenant-acme', 'a', 'acme confidential');
    seedSpace($engine, 'tenant-other', 'b', 'other tenant data');

    // Both tenants use the same view definition, which is the normal case:
    // the space is a separate axis and does not enter the filter signature.
    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'notes');
    $sync = new ViewSyncService($store, 'v1', 'epoch-1', new KeysetBootstrapSessions($store, 'secret'));

    $acme = $sync->context('tenant-acme', $view);
    $other = $sync->context('tenant-other', $view);
    $token = $sync->openBootstrap($acme, $view, 10);

    expect($sync->bootstrap($acme, $view, $token)->records)->toHaveCount(1);

    try {
        $sync->bootstrap($other, $view, $token);
        throw new LogicException('A token from another space must not be served');
    } catch (ResetRequired $reset) {
        expect($reset->reason)->toBe(ResetReason::ContextChanged);
    }
});

it('stops honouring a bootstrap token after an epoch rotation', function () {
    $store = new InMemoryStore;
    seedSpace(new Engine($store), 'tenant-acme', 'a', 'acme confidential');
    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'notes');

    $before = new ViewSyncService($store, 'v1', 'epoch-1', new KeysetBootstrapSessions($store, 'secret'));
    $token = $before->openBootstrap($before->context('tenant-acme', $view), $view, 10);

    $after = new ViewSyncService($store, 'v1', 'epoch-2', new KeysetBootstrapSessions($store, 'secret'));

    expect(fn () => $after->bootstrap($after->context('tenant-acme', $view), $view, $token))
        ->toThrow(ResetRequired::class);
});

it('stops honouring a bootstrap token after a schema version change', function () {
    $store = new InMemoryStore;
    seedSpace(new Engine($store), 'tenant-acme', 'a', 'acme confidential');
    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'notes');

    $before = new ViewSyncService($store, 'v1', 'epoch-1', new KeysetBootstrapSessions($store, 'secret'));
    $token = $before->openBootstrap($before->context('tenant-acme', $view), $view, 10);

    $after = new ViewSyncService($store, 'v2', 'epoch-1', new KeysetBootstrapSessions($store, 'secret'));

    expect(fn () => $after->bootstrap($after->context('tenant-acme', $view), $view, $token))
        ->toThrow(ResetRequired::class);
});
