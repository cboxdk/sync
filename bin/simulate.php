#!/usr/bin/env php
<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Persistence\InMemoryStore;
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
if (isset($argv[1])) {
    $seed = filter_var($argv[1], FILTER_VALIDATE_INT);
    if ($seed === false) {
        fwrite(STDERR, "Usage: composer simulate -- [integer-seed]\n");
        exit(2);
    }
    $seeds = [$seed];
}

foreach ($seeds as $seed) {
    $random = new Randomizer(new Mt19937($seed));
    $store = new InMemoryStore;
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
            verify($engine->process($mutation) == $result, 'Retry changed the persisted result');
            $retries++;
        }
    }
    $state = $store->snapshot();
    $group = array_values($state->groups)[0] ?? throw new RuntimeException('Missing conflict group');
    verify(count($group->candidates) === 100, 'Lost a competing proposal');
    $values = [];
    foreach ($group->candidates as $candidate) {
        $values[] = $candidate->value->value();
    }
    foreach (range(1, 100) as $client) {
        verify(in_array('proposal-'.$client, $values, true), 'Lost candidate '.$client);
    }
    $canonical = $state->records[$entity->key()]->value('title')->value();
    verify($canonical === 'proposal-'.$clients[0], 'Unexpected canonical value');
    $resolution = new Mutation('resolve', $entity, new Replica('moderator'), new MutationSequence(1), MutationKind::Resolve, new RecordVersion(2), [Op::set('title', 'chosen')], resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates)));
    verify($engine->process($resolution)->status === MutationStatus::Applied, 'Resolution failed');
    $late = new Mutation('late', $entity, new Replica('late'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [Op::set('title', 'late proposal')]);
    verify($engine->process($late)->status === MutationStatus::Conflict, 'Late proposal was lost');
    verify(count($store->snapshot()->groups) === 2, 'Expected archived and reopened groups');
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
