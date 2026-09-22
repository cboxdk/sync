<?php

declare(strict_types=1);

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Client\InMemoryClientState;
use Cbox\Sync\Client\Pdo\PdoClientState;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldState;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\Views\CursorContext;

/**
 * Both implementations of the device's local state, in one run. A device is
 * developed against memory and shipped on SQLite; a difference between them
 * meets its first user in production.
 */
function clientStates(): array
{
    return [
        'memory' => fn (): ClientState => new InMemoryClientState,
        'sqlite' => function (): ClientState {
            $state = new PdoClientState(new PDO('sqlite::memory:'));
            $state->migrate();

            return $state;
        },
    ];
}

function localRecord(string $id, string $title, int $version = 1): EntityRecord
{
    return new EntityRecord(new EntityKey('team', 'notes', $id), new RecordVersion($version), ['title' => new FieldState(FieldValue::of($title))]);
}

it('keeps records, versions, tombstones and memberships the same way', function (ClientState $state) {
    $key = new EntityKey('team', 'notes', 'a');
    $state->transaction(function () use ($state, $key): void {
        $state->putRecord(localRecord('a', 'first', 3));
        $state->setVersion($key, 3);
        $state->addMembership($key, 'ctx-1');
        $state->addMembership($key, 'ctx-2');
        $state->removeMembership($key, 'ctx-2');
        $state->setTombstone(new EntityKey('team', 'notes', 'gone'), 7);
    });

    expect($state->record($key)?->value('title')->value())->toBe('first')
        ->and($state->version($key))->toBe(3)
        ->and($state->version(new EntityKey('team', 'notes', 'never')))->toBe(0)
        ->and($state->memberships($key))->toBe(['ctx-1'])
        ->and($state->members('ctx-1'))->toHaveCount(1)
        ->and($state->tombstone(new EntityKey('team', 'notes', 'gone')))->toBe(7)
        ->and($state->tombstone($key))->toBeNull();
})->with(clientStates());

it('takes everything back when a transaction fails', function (ClientState $state) {
    $key = new EntityKey('team', 'notes', 'a');
    $state->transaction(fn () => $state->putRecord(localRecord('a', 'kept')));

    try {
        $state->transaction(function () use ($state, $key): void {
            $state->putRecord(localRecord('a', 'lost', 2));
            $state->addMembership($key, 'ctx');
            $state->setTombstone(new EntityKey('team', 'notes', 'b'), 4);

            throw new RuntimeException('the process died');
        });
    } catch (RuntimeException) {
    }

    expect($state->record($key)?->value('title')->value())->toBe('kept')
        ->and($state->memberships($key))->toBe([])
        ->and($state->tombstone(new EntityKey('team', 'notes', 'b')))->toBeNull();
})->with(clientStates());

it('keeps view contexts, cursors and bootstrap progress the same way', function (ClientState $state) {
    $context = new CursorContext('team', 'open', '1', 'sig', 'v1', 'epoch-1');
    $key = $context->fingerprint();

    $state->transaction(function () use ($state, $context, $key): void {
        $state->putContext($context);
        $state->setNextBootstrapToken($key, 'token-2');
        $state->markBootstrapTokenApplied($key, 'token-1');
    });

    expect($state->context($key)?->fingerprint())->toBe($key)
        ->and($state->nextBootstrapToken($key))->toBe('token-2')
        ->and($state->bootstrapTokenApplied($key, 'token-1'))->toBeTrue()
        ->and($state->bootstrapTokenApplied($key, 'token-9'))->toBeFalse();

    $state->transaction(fn () => $state->forgetView($key));

    expect($state->context($key))->toBeNull()
        ->and($state->nextBootstrapToken($key))->toBeNull()
        ->and($state->bootstrapTokenApplied($key, 'token-1'))->toBeFalse();
})->with(clientStates());
