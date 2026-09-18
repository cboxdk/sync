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
