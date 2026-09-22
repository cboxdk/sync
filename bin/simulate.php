#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\Testing\FakeIdGenerator;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;
use Random\Engine\Mt19937;
use Random\Randomizer;

require dirname(__DIR__).'/vendor/autoload.php';

/** Throw independently of PHP's assertions configuration. */
function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$seeds = [7, 42, 2026];
$driver = 'memory';
$dsn = '';
$user = '';
$password = '';
/** @var list<string> $arguments */
$arguments = array_slice(is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [], 1);
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--store=')) {
        $driver = substr($argument, 8);

        continue;
    }
    if (str_starts_with($argument, '--dsn=')) {
        $dsn = substr($argument, 6);
        $driver = 'dsn';

        continue;
    }
    if (str_starts_with($argument, '--user=')) {
        $user = substr($argument, 7);

        continue;
    }
    if (str_starts_with($argument, '--password=')) {
        $password = substr($argument, 11);

        continue;
    }
    $seed = filter_var($argument, FILTER_VALIDATE_INT);
    if ($seed === false) {
        fwrite(STDERR, "Usage: composer simulate -- [integer-seed] [--store=memory|sqlite] [--dsn=... --user=... --password=...]\n");
        exit(2);
    }
    $seeds = [$seed];
}
if (! in_array($driver, ['memory', 'sqlite', 'dsn'], true)) {
    fwrite(STDERR, "Unknown store: $driver\n");
    exit(2);
}

/** @var list<string> $temporary */
$temporary = [];
$makeStore = function () use ($driver, $dsn, $user, $password, &$temporary): Store {
    if ($driver === 'memory') {
        return new InMemoryStore;
    }
    if ($driver === 'dsn') {
        $connection = new PDO($dsn, $user === '' ? null : $user, $password === '' ? null : $password);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Each seed is an independent run, so the scratch database starts empty.
        foreach (['sync_commits', 'sync_conflict_groups', 'sync_fields', 'sync_records', 'sync_receipts', 'sync_streams', 'sync_spaces'] as $table) {
            $connection->exec('DROP TABLE IF EXISTS '.$table);
        }
        $store = new PdoStore($connection);
        $store->migrate();

        return $store;
    }
    $database = tempnam(sys_get_temp_dir(), 'cbox-sync-sim-').'.sqlite';
    $temporary[] = $database;
    $store = new PdoStore(new PDO('sqlite:'.$database));
    $store->migrate();

    return $store;
};

foreach ($seeds as $seed) {
    $random = new Randomizer(new Mt19937($seed));
    $store = $makeStore();
    $engine = new Engine($store, ids: new FakeIdGenerator);
    $entity = new EntityKey('demo', 'notes', 'shared-note');
    $engine->process(new Mutation('create', $entity, new Replica('server'), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [Op::set('title', 'initial')]));
    $clients = range(1, 100);
    // Fisher–Yates preserves integer client identities.
    for ($i = count($clients) - 1; $i > 0; $i--) {
        $j = $random->getInt(0, $i);
        [$clients[$i], $clients[$j]] = [$clients[$j], $clients[$i]];
    }
    $retries = 0;
    foreach ($clients as $client) {
        $mutation = new Mutation('client-'.$client, $entity, new Replica('replica-'.$client), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [Op::set('title', 'proposal-'.$client)]);
        $result = $engine->process($mutation);
        // Lose the first response and resend exactly the same request.
        foreach (range(1, $random->getInt(1, 4)) as $_) {
            // Structural equality on purpose: a replay rebuilds the result
            // from storage, so it is an equal object, never the same one.
            verify(serialize($engine->process($mutation)) === serialize($result), 'Retry changed the persisted result');
            $retries++;
        }
    }
    $group = $store->openGroups($entity)[0] ?? throw new RuntimeException('Missing conflict group');
    verify(count($group->candidates) === 100, 'Lost a competing proposal');
    $values = [];
    foreach ($group->candidates as $candidate) {
        $values[] = $candidate->value->value();
    }
    foreach (range(1, 100) as $client) {
        verify(in_array('proposal-'.$client, $values, true), 'Lost candidate '.$client);
    }
    $canonical = ($store->record($entity) ?? throw new RuntimeException('Missing canonical record'))->value('title')->value();
    verify($canonical === 'proposal-'.$clients[0], 'Unexpected canonical value');
    $resolution = new Mutation('resolve', $entity, new Replica('moderator'), new MutationSequence(1), MutationKind::Resolve, new RecordVersion(2), [Op::set('title', 'chosen')], resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates)));
    verify($engine->process($resolution)->status === MutationStatus::Applied, 'Resolution failed');
    $late = new Mutation('late', $entity, new Replica('late'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [Op::set('title', 'late proposal')]);
    verify($engine->process($late)->status === MutationStatus::Conflict, 'Late proposal was lost');
    verify($store->group($group->id)?->isOpen() === false, 'Resolved group stayed open');
    verify(count($store->openGroups($entity)) === 1, 'Expected exactly one reopened group');
    $cursor = 0;
    $commits = 0;
    $pages = 0;
    do {
        $page = $store->pull('demo', $cursor, 1);
        foreach ($page->commits as $commit) {
            verify($commit->sequence->value === $cursor + 1, 'Feed skipped or repeated a commit');
            verify(count($commit->changes) >= 2, 'A commit was split');
            $cursor = $commit->sequence->value;
            $commits++;
        }
        verify($page->nextCursor->value === $cursor, 'Cursor differs from last whole commit');
        $pages++;
    } while ($page->hasMore);
    verify($commits === 103, 'Retries emitted extra commits');
    echo "seed={$seed}: 100/100 proposals preserved; canonical=proposal-{$clients[0]}; {$retries} retries; resolution + late candidate preserved; {$commits} whole commits / {$pages} pages. OK\n";
}

foreach ($temporary as $database) {
    @unlink($database);
}
