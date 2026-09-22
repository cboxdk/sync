<?php

declare(strict_types=1);

/**
 * Proves what a single-process test cannot: that real concurrent writers in one
 * space produce a gapless commit log whose numbering matches the order the
 * commits became visible.
 *
 * Each writer is its own OS process with its own connection, so the space row
 * lock is the only thing serializing them.
 *
 * Usage:
 *   php bin/concurrency.php --dsn="pgsql:host=127.0.0.1;dbname=sync" --user=sync --password=secret
 *   php bin/concurrency.php                       # sqlite, smoke only: one writer at a time
 */

require __DIR__.'/../vendor/autoload.php';

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/** @return array<string, string> */
function options(): array
{
    $options = ['dsn' => '', 'user' => '', 'password' => '', 'writers' => '4', 'mutations' => '25', 'worker' => '', 'spaces' => 'shared'];
    /** @var list<string> $arguments */
    $arguments = array_slice(is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [], 1);
    foreach ($arguments as $argument) {
        if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $matches) === 1 && array_key_exists($matches[1], $options)) {
            $options[$matches[1]] = $matches[2];
        }
    }

    return $options;
}

function requireEmptySpace(PdoStore $store, string $space): void
{
    if ($store->watermark($space)->value !== 0) {
        fwrite(STDERR, "Space '$space' is not empty; point --dsn at a scratch database\n");
        exit(2);
    }
}

function connect(string $dsn, string $user, string $password): PdoStore
{
    return new PdoStore(new PDO($dsn === '' ? 'sqlite:'.sys_get_temp_dir().'/cbox-sync-concurrency.sqlite' : $dsn, $user === '' ? null : $user, $password === '' ? null : $password));
}

$options = options();
// shared: every writer in one space - the serialized path.
// separate: one space each - writers that must not block one another at all.
$separate = $options['spaces'] === 'separate';
$spaceOf = fn (string $worker): string => $separate ? 'concurrency-'.$worker : 'concurrency';
$mutations = max(1, (int) $options['mutations']);

if ($options['worker'] !== '') {
    $store = connect($options['dsn'], $options['user'], $options['password']);
    $engine = new Engine($store);
    $worker = $options['worker'];
    $space = $spaceOf($worker);
    $retries = 0;
    for ($sequence = 1; $sequence <= $mutations; $sequence++) {
        $entity = new EntityKey($space, 'notes', $worker.'-'.$sequence);
        // Mutation identity is global, not per space: named after the space
        // too, so a run in separate spaces never collides with a shared one
        // on the same database.
        $mutation = new Mutation(
            $space.'/'.$worker.'-'.$sequence, $entity, new Replica($worker),
            new MutationSequence($sequence), MutationKind::Create, new RecordVersion(0),
            [Op::set('title', $worker.'/'.$sequence)],
        );
        for ($attempt = 1; ; $attempt++) {
            try {
                $engine->process($mutation);
                break;
            } catch (TransientFailure $contention) {
                // Only what the store classifies as contention is retried. A
                // raw driver exception here is a defect, and fails the run.
                $retries++;
                if ($attempt >= 50) {
                    fwrite(STDERR, "worker $worker gave up: ".$contention->getMessage()."\n");
                    exit(1);
                }
                usleep(random_int(1_000, 20_000));
            }
        }
    }
    echo $retries;
    exit(0);
}

$writers = max(1, (int) $options['writers']);
if ($options['dsn'] === '') {
    @unlink(sys_get_temp_dir().'/cbox-sync-concurrency.sqlite');
}
$store = connect($options['dsn'], $options['user'], $options['password']);
$store->migrate();
for ($worker = 1; $worker <= (int) $options['writers']; $worker++) {
    requireEmptySpace($store, $spaceOf('w'.$worker));
}

$processes = [];
for ($worker = 1; $worker <= $writers; $worker++) {
    $command = sprintf(
        '%s %s --worker=w%d --mutations=%d --spaces=%s --dsn=%s --user=%s --password=%s',
        escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), $worker, $mutations, escapeshellarg($options['spaces']),
        escapeshellarg($options['dsn']), escapeshellarg($options['user']), escapeshellarg($options['password']),
    );
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        fwrite(STDERR, "Could not start worker $worker\n");
        exit(1);
    }
    $processes[] = [$process, $pipes];
}

$failed = false;
$retries = 0;
foreach ($processes as [$process, $pipes]) {
    $retries += (int) stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, (string) $error);
        $failed = true;
    }
}
if ($failed) {
    exit(1);
}

$problems = [];
$spaces = $separate ? array_map(fn (int $w): string => $spaceOf('w'.$w), range(1, $writers)) : [$spaceOf('w1')];
$perSpace = $separate ? $mutations : $writers * $mutations;
foreach ($spaces as $space) {
    $watermark = $store->watermark($space)->value;
    $sequences = [];
    $cursor = 0;
    do {
        $page = $store->pull($space, $cursor, 100);
        foreach ($page->commits as $commit) {
            $sequences[] = $commit->sequence->value;
        }
        $cursor = $page->nextCursor->value;
    } while ($page->hasMore);

    if ($watermark !== $perSpace) {
        $problems[] = "$space: watermark is $watermark, expected $perSpace";
    }
    if ($sequences !== range(1, $perSpace)) {
        $problems[] = "$space: commit sequences are not a gapless ascending run of $perSpace";
    }
}
for ($worker = 1; $worker <= $writers; $worker++) {
    if ($store->acknowledged($spaceOf('w'.$worker), new Replica('w'.$worker)) !== $mutations) {
        $problems[] = "replica w$worker did not acknowledge $mutations mutations";
    }
}
$expected = $writers * $mutations;

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, "FAIL: $problem\n");
    }
    exit(1);
}

printf(
    "%s: %d writers x %d mutations in %s space(s) = %d gapless commits, every replica fully acknowledged, %d contention retries. OK\n",
    $options['dsn'] === '' ? 'sqlite' : explode(':', $options['dsn'])[0],
    $writers, $mutations, $separate ? 'separate' : 'one', $expected, $retries,
);
