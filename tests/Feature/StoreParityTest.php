<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\FieldPredicate;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\HistoryUnavailable;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/**
 * Two implementations of one contract have to answer identically, or a host
 * that develops against one and deploys on the other meets the difference in
 * production.
 */
function parityStores(): array
{
    $durable = new PdoStore(new PDO('sqlite::memory:'));
    $durable->migrate();

    return ['memory' => new InMemoryStore, 'sqlite' => $durable];
}

function seed(Store $store, string $id, array $operations): void
{
    (new Engine($store))->process(new Mutation(
        'seed-'.$id, new EntityKey('space', 'notes', $id), new Replica('r-'.$id),
        new MutationSequence(1), MutationKind::Create, new RecordVersion(0), $operations,
    ));
}

it('matches a record on a field that was never set', function (Store $store) {
    seed($store, 'with', [Op::set('status', 'open'), Op::set('archived_at', 'yesterday')]);
    seed($store, 'without', [Op::set('status', 'open')]);

    // "this field has no value" is a legitimate thing for a view to filter on,
    // and a never-set field has no row to match against.
    $criteria = new RecordCriteria('notes', [new FieldPredicate('archived_at', FieldValue::missing())]);
    $found = array_map(fn ($record): string => $record->entity->id, $store->scanRecords('space', null, 10, $criteria));

    expect($found)->toBe(['without']);
})->with(parityStores());

it('does not rewind the commit sequence when all history is pruned', function (Store $store) {
    seed($store, 'a', [Op::set('status', 'open')]);
    expect($store->watermark('space')->value)->toBe(1);

    $store->prune('space', new CommitSequence(2));

    // A client already at cursor 1 would miss everything if the next commit
    // reused sequence 1.
    expect($store->watermark('space')->value)->toBe(1);
    seed($store, 'b', [Op::set('status', 'open')]);
    expect($store->watermark('space')->value)->toBe(2);
})->with(parityStores());

it('refuses a read whose history was pruned underneath it', function () {
    $database = tempnam(sys_get_temp_dir(), 'cbox-prune-').'.sqlite';
    try {
        $open = function () use ($database): PdoStore {
            $store = new PdoStore(new PDO('sqlite:'.$database));
            $store->migrate();

            return $store;
        };
        $store = $open();
        foreach (['a', 'b', 'c'] as $id) {
            seed($store, $id, [Op::set('status', 'open')]);
        }

        // Another connection prunes everything the reader was about to fetch.
        $open()->prune('space', new CommitSequence(3));

        // Returning only commit 3 and letting the cursor advance to 3 would
        // lose commits 1 and 2 permanently, with nothing to signal it.
        expect(fn () => $store->commitsAfter('space', 0, 10))
            ->toThrow(HistoryUnavailable::class);
    } finally {
        @unlink($database);
    }
});

/**
 * Identifiers are client input, and the contract promises byte order. PHP
 * compares two numeric strings numerically, so an in-memory store that uses
 * array comparison orders them differently from any database - and treats
 * distinct ids as equal, which is how a keyset page skips one entirely.
 */
it('orders identifiers by bytes, not by what they look like', function (Store $store) {
    foreach (['9', '10', '100', '1e2', '2', '01'] as $id) {
        seed($store, $id, [Op::set('status', 'open')]);
    }

    $found = array_map(fn ($record): string => $record->entity->id, $store->scanRecords('space', null, 50));

    $expected = ['01', '1e2', '10', '100', '2', '9'];
    sort($expected, SORT_STRING);

    expect($found)->toBe($expected);
})->with(parityStores());

/**
 * The same ids again, paged one at a time. A cursor that treats two distinct
 * ids as equal drops the second, and the page still reports itself finished -
 * so the delta never repairs it.
 */
it('pages every identifier exactly once', function (Store $store) {
    $ids = ['9', '10', '100', '1e2', '2', '01'];
    foreach ($ids as $id) {
        seed($store, $id, [Op::set('status', 'open')]);
    }

    $seen = [];
    $after = null;
    while (($page = $store->scanRecords('space', $after, 1)) !== []) {
        $seen[] = $page[0]->entity->id;
        $after = $page[0]->entity;
    }

    sort($seen, SORT_STRING);
    $expected = $ids;
    sort($expected, SORT_STRING);

    expect($seen)->toBe($expected);
})->with(parityStores());

/** A trailing space is a different identifier, and MySQL's PAD SPACE says otherwise. */
it('keeps an identifier that differs only in trailing whitespace distinct', function (Store $store) {
    seed($store, 'pad', [Op::set('status', 'open')]);
    seed($store, 'pad ', [Op::set('status', 'closed')]);

    expect($store->record(new EntityKey('space', 'notes', 'pad'))?->value('status')->value())->toBe('open');
    expect($store->record(new EntityKey('space', 'notes', 'pad '))?->value('status')->value())->toBe('closed');
})->with(parityStores());

/**
 * A selective view's page has to cost the page, not the space. The predicate is
 * matched from an index that also carries the keyset columns, so the same index
 * both finds the records and hands them over in order.
 */
it('pages a selective predicate without reading the whole space', function (Store $store) {
    foreach (range(1, 60) as $i) {
        seed($store, sprintf('e%03d', $i), [Op::set('project', $i > 57 ? 'alpha' : 'beta')]);
    }

    $criteria = new RecordCriteria('notes', [new FieldPredicate('project', FieldValue::of('alpha'))]);

    $seen = [];
    $after = null;
    while (($page = $store->scanRecords('space', $after, 2, $criteria)) !== []) {
        foreach ($page as $record) {
            $seen[] = $record->entity->id;
        }
        $after = $page[count($page) - 1]->entity;
    }

    expect($seen)->toBe(['e058', 'e059', 'e060']);
})->with(parityStores());

/** Two predicates still both apply, whichever one leads the query. */
it('applies every predicate when more than one is given', function (Store $store) {
    seed($store, 'both', [Op::set('project', 'alpha'), Op::set('status', 'open')]);
    seed($store, 'project-only', [Op::set('project', 'alpha'), Op::set('status', 'done')]);
    seed($store, 'status-only', [Op::set('project', 'beta'), Op::set('status', 'open')]);

    $criteria = new RecordCriteria('notes', [
        new FieldPredicate('project', FieldValue::of('alpha')),
        new FieldPredicate('status', FieldValue::of('open')),
    ]);

    $found = array_map(fn ($record): string => $record->entity->id, $store->scanRecords('space', null, 10, $criteria));

    expect($found)->toBe(['both']);
})->with(parityStores());
