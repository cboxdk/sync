<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/** The engine inside a transaction someone else began, as a framework adapter runs it. */
class HostOwnedStore extends PdoStore
{
    protected function needsLockingReads(): bool
    {
        return true;
    }

    protected function begin(): void {}

    protected function commit(): void {}

    protected function rollback(): void {}
}

/**
 * Inside a host transaction at MySQL's REPEATABLE READ, the snapshot is taken
 * before the space lock. A duplicate delivery whose first copy committed after
 * that snapshot found no receipt, was answered sequence_behind, and the device
 * renumbered a write that had landed - then abandoned it as a reused identity.
 */
it('answers a duplicate from its receipt even when the host read before the lock', function () {
    $dsn = (string) (getenv('SYNC_DSN') ?: '');
    if (! str_starts_with($dsn, 'mysql:')) {
        $this->markTestSkipped('The stale snapshot is MySQL\'s.');
    }
    $shared = $this->databaseStore() ?? throw new LogicException('expected a database');
    $host = new PDO($dsn, (string) (getenv('SYNC_DB_USER') ?: '') ?: null, (string) (getenv('SYNC_DB_PASSWORD') ?: '') ?: null);
    $host->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $key = new EntityKey('team', 'notes', 'n1');
    $device = new Replica('device');
    $first = new Mutation('m1', $key, $device, new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [Op::set('title', 'a')]);
    $second = new Mutation('m2', $key, $device, new MutationSequence(2), MutationKind::Update, new RecordVersion(1), [Op::set('title', 'b')], dependsOn: 'm1');
    $engine = new Engine($shared);
    $engine->process($first);

    $host->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $host->exec('START TRANSACTION');
    $host->query('SELECT COUNT(*) FROM sync_receipts')->fetchColumn();
    $delivered = $engine->process($second);
    $duplicate = (new Engine(new HostOwnedStore($host)))->process($second);
    $host->exec('COMMIT');

    expect($delivered->status)->toBe(MutationStatus::Applied)
        ->and($duplicate)->toEqual($delivered);
});
