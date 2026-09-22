<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\RecordCriteria;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Persistence\Pdo\PdoSchema;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\Resolvers\RejectOnConflict;
use Cbox\Sync\Testing\FakeIdGenerator;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Cbox\Sync\Views\FieldEqualsView;

/**
 * The properties only a real database can demonstrate. A file rather than
 * :memory: so a second connection can observe the first.
 */
beforeEach(function () {
    $this->database = tempnam(sys_get_temp_dir(), 'cbox-sync-').'.sqlite';
    $this->store = connectTo($this->database);
    $this->store->migrate();
    $this->engine = new Engine($this->store, ids: new FakeIdGenerator);
    $this->key = new EntityKey('test', 'notes', 'one');
});

afterEach(function () {
    @unlink($this->database);
});

function connectTo(string $database): PdoStore
{
    return new PdoStore(new PDO('sqlite:'.$database));
}

it('keeps state across store instances', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'durable')]);

    $reopened = connectTo($this->database);

    expect($reopened->record($this->key)?->value('title')->value())->toBe('durable');
    expect($reopened->watermark('test')->value)->toBe(2);
    expect($reopened->receipt('a-1')?->result->status)->toBe(MutationStatus::Applied);
});

it('hides writes from another connection until the transaction commits', function () {
    $this->seedRecord();
    $observer = connectTo($this->database);
    expect($observer->record($this->key)?->version->value)->toBe(1);

    $this->store->transaction('test', function (Ledger $ledger) use ($observer): MutationResult {
        $ledger->putRecord(new EntityRecord($this->key, new RecordVersion(99)));

        expect($ledger->record($this->key)?->version->value)->toBe(99);
        expect($observer->record($this->key)?->version->value)->toBe(1);

        return new MutationResult(MutationStatus::Noop);
    });

    expect(connectTo($this->database)->record($this->key)?->version->value)->toBe(99);
});

it('consumes no commit sequence when a transaction rolls back', function () {
    $this->seedRecord();
    expect($this->store->watermark('test')->value)->toBe(1);

    try {
        $this->store->transaction('test', function (Ledger $ledger): MutationResult {
            $ledger->appendCommit(new CommitSequence(2), []);

            throw new RuntimeException('adapter failure after staging everything');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->store->watermark('test')->value)->toBe(1);
    expect(connectTo($this->database)->watermark('test')->value)->toBe(1);

    // The next mutation takes 2, not 3: nothing was burned.
    expect($this->write('a', 1, [Op::set('title', 'next')])->commitSequence?->value)->toBe(2);
});

it('discards domain state on a rejected mutation while still publishing its receipt', function () {
    $this->engine = new Engine($this->store, new RejectOnConflict, new FakeIdGenerator);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $version = $this->store->record($this->key)?->version->value;

    $rejected = $this->write('b', 1, [Op::set('title', 'B')]);

    expect($rejected->status)->toBe(MutationStatus::Rejected);
    // The savepoint rolled the record back; everything outside it committed.
    $reopened = connectTo($this->database);
    expect($reopened->record($this->key)?->version->value)->toBe($version);
    expect($reopened->record($this->key)?->value('title')->value())->toBe('A');
    expect($reopened->receipt('b-1')?->result->status)->toBe(MutationStatus::Rejected);
    expect($reopened->acknowledged('test', new Replica('b')))->toBe(1);
    expect($reopened->watermark('test')->value)->toBe(3);
    expect($reopened->openGroups($this->key))->toBeEmpty();
});

it('enforces at most one open conflict group per field in the database itself', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $open = $this->store->openGroups($this->key);
    expect($open)->toHaveCount(1);

    $candidate = array_values($open[0]->candidates)[0];
    $duplicate = new ConflictGroup('a-second-open-group', $this->key, 'title', 1, [$candidate->id => $candidate]);

    expect(fn () => $this->store->transaction('test', function (Ledger $ledger) use ($duplicate): MutationResult {
        $ledger->putGroup($duplicate);

        return new MutationResult(MutationStatus::Noop);
    }))->toThrow(PDOException::class);

    expect(connectTo($this->database)->openGroups($this->key))->toHaveCount(1);
});

it('serves a bootstrap scan from the field index', function () {
    $scan = fn (?RecordCriteria $criteria) => $this->store->scanRecords('test', null, 10, $criteria);

    foreach ([['a', 'alpha'], ['b', 'beta'], ['c', 'alpha']] as [$id, $project]) {
        $this->engine->process(new Mutation(
            'seed-'.$id, new EntityKey('test', 'items', $id), new Replica('r-'.$id),
            new MutationSequence(1), MutationKind::Create, new RecordVersion(0),
            [Op::set('project', $project)],
        ));
    }

    $view = FieldEqualsView::matching('by-project', '1', 'project', 'alpha', 'items');
    $matched = $scan($view->criteria());

    expect(array_map(fn ($record): string => $record->entity->id, $matched))->toBe(['a', 'c']);
    expect($scan(null))->toHaveCount(3);
});

/**
 * Receipts from before they carried their position were never found by a
 * position lookup, so a locking read inside a host transaction locked the gap
 * where they would be - which can border another tenant's range.
 */
it('gives receipts from an earlier release their stream position on migrate', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    $store->migrate();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'n1');
    $engine->process(new Mutation('m1', $key, new Replica('device'), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [FieldOperation::set('title', 'a')]));
    $pdo->exec('UPDATE sync_receipts SET replica_id = NULL, sequence = NULL');

    // As a host's migration runs it: the schema installed inside a transaction.
    $pdo->beginTransaction();
    (new PdoSchema('sqlite'))->install($pdo);
    $pdo->commit();

    expect($pdo->query("SELECT replica_id || ':' || sequence FROM sync_receipts WHERE mutation_id = 'm1'")->fetchColumn())->toBe('device:1');
});

/** A stream whose receipts and acknowledged position disagree met the same row on every retry, reported as worth retrying. */
it('refuses a position that already has an answer as a disagreement, not as busy', function () {
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    $store->migrate();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'n1');
    $device = new Replica('device');
    $engine->process(new Mutation('m1', $key, $device, new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [FieldOperation::set('title', 'a')]));
    // A partial restore: the stream forgot, the receipt did not.
    $pdo->exec('UPDATE sync_streams SET acknowledged = 0');

    expect(fn () => $engine->process(new Mutation('m2', new EntityKey('team', 'notes', 'n2'), $device, new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [FieldOperation::set('title', 'b')])))
        ->toThrow(LogicException::class, 'disagree');
});
