<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Testing\FailingStore;
use Cbox\Sync\Testing\FakeIdGenerator;
use Cbox\Sync\ValueObjects\RecordVersion;

it('rolls back domain state conflicts receipts acknowledgements and feed then safely retries', function () {
    $store = new FailingStore;
    $this->store = $store;
    $this->engine = new Engine($store, ids: new FakeIdGenerator);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $before = serialize($store->snapshot());
    $mutation = $this->mutation('b', 1, [Op::set('title', 'B'), Op::set('body', 'new body')], atomic: false);
    $store->failNextCommit();
    expect(fn () => $this->engine->process($mutation))->toThrow(TransientFailure::class);
    expect(serialize($store->snapshot()))->toBe($before);
    $result = $this->engine->process($mutation);
    expect($result->acknowledgedSequence)->toBe(1);
    expect($result->commitSequence->value)->toBe(3);
    expect($this->record()->value('body')->value())->toBe('new body');
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
    expect($this->engine->process($mutation))->toEqual($result);
});

it('does not expose transaction writes before commit or allow nested writes', function () {
    $this->seedRecord();
    $before = serialize($this->store->snapshot());
    expect(function () use ($before) {
        $this->store->transaction($this->key->space, function (Ledger $ledger) use ($before) {
            $ledger->putRecord(new EntityRecord($this->key, new RecordVersion(99)));
            expect(serialize($this->store->snapshot()))->toBe($before);

            return $this->write('a', 1, [Op::set('title', 'nested')]);
        });
    })->toThrow(TransientFailure::class);
    expect(serialize($this->store->snapshot()))->toBe($before);
});

it('isolates exported snapshots and nested field values from mutation', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('object', (object) ['nested' => (object) ['n' => 1]])]);
    $snapshot = $this->store->snapshot();
    $record = $snapshot->records[$this->key->key()];
    $value = $record->value('object')->value();
    $value->nested->n = 100;
    $snapshot->records = [];
    expect($this->record()->value('object')->value()->nested->n)->toBe(1);
});

it('freezes caller array references before journaling mutation identity', function () {
    $operation = Op::set('title', 'first');
    $mutation = $this->mutation('a', 1, [&$operation], 0, kind: MutationKind::Create);
    $originalFingerprint = $mutation->fingerprint();
    $first = $this->engine->process($mutation);
    $operation = Op::set('title', 'changed');
    expect($mutation->fingerprint())->toBe($originalFingerprint);
    expect($this->store->snapshot()->receipts['a-1']->mutation->operations[0]->value->value())->toBe('first');
    expect($this->engine->process($mutation))->toEqual($first);
    expect(fn () => $this->engine->process($this->mutation('a', 1, [$operation], 0, kind: MutationKind::Create)))->toThrow(ProtocolException::class);
});
