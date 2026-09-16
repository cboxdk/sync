<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Inspectable;
use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/**
 * The ledger is what a durable adapter has to reproduce. These are the
 * properties the engine depends on, stated without going through the engine.
 */
function inLedger(object $test, Closure $callback): mixed
{
    return $test->store->transaction('test', $callback);
}

it('reflects its own writes without exposing them before commit', function () {
    $key = new EntityKey('test', 'notes', 'one');

    $this->inTransaction(function (Ledger $ledger) use ($key): MutationResult {
        expect($ledger->record($key))->toBeNull();
        expect($ledger->recordChanged($key))->toBeFalse();

        $ledger->putRecord(new EntityRecord($key, new RecordVersion(1)));

        expect($ledger->record($key)?->version->value)->toBe(1);
        expect($ledger->recordChanged($key))->toBeTrue();
        if ($this->store instanceof Inspectable) {
            // Reading through the store is a second observer only for the
            // in-memory adapter; on one PDO connection it is the same
            // transaction. PdoStoreTest proves the durable case across
            // connections.
            expect($this->store->record($key))->toBeNull();
        }

        return new MutationResult(MutationStatus::Noop);
    });

    expect($this->store->record($key)?->version->value)->toBe(1);
});

it('discards records groups and change tracking when a draft rolls back', function () {
    $key = new EntityKey('test', 'notes', 'one');

    $this->inTransaction(function (Ledger $ledger) use ($key): MutationResult {
        $ledger->putRecord(new EntityRecord($key, new RecordVersion(1)));
        $ledger->beginDraft();
        $ledger->putRecord(new EntityRecord($key, new RecordVersion(2)));
        expect($ledger->record($key)?->version->value)->toBe(2);

        $ledger->rollbackDraft();

        expect($ledger->record($key)?->version->value)->toBe(1);
        // The write from before the draft still counts as a change.
        expect($ledger->recordChanged($key))->toBeTrue();
        expect($ledger->touchedGroups())->toBe([]);

        return new MutationResult(MutationStatus::Noop);
    });

    expect($this->store->record($key)?->version->value)->toBe(1);
});

it('clears change tracking for a write made only inside a rolled back draft', function () {
    $key = new EntityKey('test', 'notes', 'one');

    $this->inTransaction(function (Ledger $ledger) use ($key): MutationResult {
        $ledger->beginDraft();
        $ledger->putRecord(new EntityRecord($key, new RecordVersion(1)));
        $ledger->rollbackDraft();

        expect($ledger->record($key))->toBeNull();
        expect($ledger->recordChanged($key))->toBeFalse();

        return new MutationResult(MutationStatus::Noop);
    });

    expect($this->store->record($key))->toBeNull();
});

it('keeps a draft that is committed', function () {
    $key = new EntityKey('test', 'notes', 'one');

    $this->inTransaction(function (Ledger $ledger) use ($key): MutationResult {
        $ledger->beginDraft();
        $ledger->putRecord(new EntityRecord($key, new RecordVersion(7)));
        $ledger->commitDraft();

        expect($ledger->record($key)?->version->value)->toBe(7);

        return new MutationResult(MutationStatus::Noop);
    });

    expect($this->store->record($key)?->version->value)->toBe(7);
});

it('treats the watermark as a pure read and only appendCommit as consuming', function () {
    $this->inTransaction(function (Ledger $ledger): MutationResult {
        expect($ledger->watermark()->value)->toBe(0);
        expect($ledger->watermark()->value)->toBe(0);
        $ledger->appendCommit(new CommitSequence(1), []);
        expect($ledger->watermark()->value)->toBe(1);

        return new MutationResult(MutationStatus::Noop);
    });

    expect($this->store->watermark('test')->value)->toBe(1);

    $this->inTransaction(function (Ledger $ledger): MutationResult {
        expect(fn () => $ledger->appendCommit(new CommitSequence(3), []))->toThrow(ProtocolException::class);

        return new MutationResult(MutationStatus::Noop);
    });
});

it('refuses a mutation identity that already exists', function () {
    $this->seedRecord();
    $receipt = $this->store->receipt('seed-1') ?? throw new LogicException('Seed receipt expected');

    $this->inTransaction(function (Ledger $ledger) use ($receipt): MutationResult {
        expect(fn () => $ledger->putReceipt($receipt))->toThrow(TransientFailure::class);

        return new MutationResult(MutationStatus::Noop);
    });
});

it('scopes acknowledgements to the replica and the ledger space', function () {
    $this->inTransaction(function (Ledger $ledger): MutationResult {
        expect($ledger->space())->toBe('test');
        expect($ledger->acknowledged(new Replica('a')))->toBe(0);
        $ledger->acknowledge(new Replica('a'), 4);
        expect($ledger->acknowledged(new Replica('a')))->toBe(4);
        expect($ledger->acknowledged(new Replica('b')))->toBe(0);

        return new MutationResult(MutationStatus::Noop);
    });

    expect($this->store->acknowledged('test', new Replica('a')))->toBe(4);
    expect($this->store->acknowledged('other', new Replica('a')))->toBe(0);
});
