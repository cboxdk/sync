<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Inspectable;
use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\Testing\FailingStoreFactory;
use Cbox\Sync\Testing\FakeIdGenerator;
use Cbox\Sync\ValueObjects\RecordVersion;

it('rolls back domain state conflicts receipts acknowledgements and feed then safely retries', function () {
    // Whichever adapter the suite is running against, so the rollback this
    // pins is proven against a real database in CI and not only against an
    // array.
    $store = FailingStoreFactory::make();
    $this->store = $store;
    $this->engine = new Engine($store, ids: new FakeIdGenerator);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $before = $this->storeDigest();
    $mutation = $this->mutation('b', 1, [Op::set('title', 'B'), Op::set('body', 'new body')], atomic: false);
    $store->failNextCommit();
    expect(fn () => $this->engine->process($mutation))->toThrow(TransientFailure::class);
    expect($this->storeDigest())->toBe($before);
    $result = $this->engine->process($mutation);
    expect($result->acknowledgedSequence)->toBe(1);
    expect($result->commitSequence->value)->toBe(3);
    expect($this->record()->value('body')->value())->toBe('new body');
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
    expect($this->engine->process($mutation))->toEqual($result);
});

it('does not expose transaction writes before commit or allow nested writes', function () {
    $this->seedRecord();
    $before = $this->storeDigest();
    expect(function () use ($before) {
        $this->store->transaction($this->key->space, function (Ledger $ledger) use ($before) {
            $ledger->putRecord(new EntityRecord($this->key, new RecordVersion(99)));
            if ($this->store instanceof Inspectable) {
                // Reading through the store is a second observer only for the
                // in-memory adapter; on one PDO connection it is the same
                // transaction. PdoStoreTest proves the durable case across
                // connections.
                expect($this->storeDigest())->toBe($before);
            }

            return $this->write('a', 1, [Op::set('title', 'nested')]);
        });
    })->toThrow(TransientFailure::class);
    expect($this->storeDigest())->toBe($before);
});

it('isolates exported snapshots and nested field values from mutation', function () {
    // Snapshot export is an in-memory concern: a durable store hands out fresh
    // objects anyway. Nested field isolation is checked for whichever store runs.
    $this->store = new InMemoryStore;
    $this->engine = new Engine($this->store, ids: new FakeIdGenerator);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('object', (object) ['nested' => (object) ['n' => 1]])]);
    $snapshot = $this->store->snapshot();
    $record = $snapshot->records[$this->key->key()];
    $value = $record->value('object')->value();
    $value->nested->n = 100;
    $snapshot->records = [];
    expect($this->record()->value('object')->value()->nested->n)->toBe(1);
});

it('does not let a caller mutate a nested field value it reads back', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('object', (object) ['nested' => (object) ['n' => 1]])]);
    $value = $this->record()->value('object')->value();
    $value->nested->n = 100;
    expect($this->record()->value('object')->value()->nested->n)->toBe(1);
});

it('freezes caller array references before journaling mutation identity', function () {
    $operation = Op::set('title', 'first');
    $mutation = $this->mutation('a', 1, [&$operation], 0, kind: MutationKind::Create);
    $originalFingerprint = $mutation->fingerprint();
    $first = $this->engine->process($mutation);
    $operation = Op::set('title', 'changed');
    expect($mutation->fingerprint())->toBe($originalFingerprint);
    expect($this->store->receipt('a-1')->mutation->operations[0]->value->value())->toBe('first');
    expect($this->engine->process($mutation))->toEqual($first);
    expect(fn () => $this->engine->process($this->mutation('a', 1, [$operation], 0, kind: MutationKind::Create)))->toThrow(ProtocolException::class);
});

/** A deadlock or a lock wait that timed out committed nothing; the same mutation may be sent again. */
it('answers a deadlock as a transient failure', function (string $state, int $driverCode) {
    $store = new PdoStore(new PDO('sqlite::memory:'));
    $store->migrate();
    $deadlock = new PDOException('deadlock');
    $deadlock->errorInfo = [$state, $driverCode, 'deadlock'];
    (new ReflectionProperty(Exception::class, 'code'))->setValue($deadlock, $state);

    expect(fn () => $store->transaction('s', function () use ($deadlock): never {
        throw $deadlock;
    }))->toThrow(TransientFailure::class);
})->with([
    'mysql deadlock' => ['40001', 1213],
    'mysql lock wait' => ['HY000', 1205],
    'postgres deadlock' => ['40P01', 7],
]);
