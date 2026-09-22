<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Client\Contracts\ClientState;
use Cbox\Sync\Client\InMemoryClientState;
use Cbox\Sync\Client\Pdo\PdoClientState;

/**
 * Builds the client state a suite runs against.
 *
 * SYNC_CLIENT=sqlite runs the same tests against the durable implementation, so
 * both are held to identical behaviour from one set of fixtures.
 */
class ClientStateFactory
{
    public static function make(): ClientState
    {
        if (Environment::get('SYNC_CLIENT', 'memory') !== 'sqlite') {
            return new InMemoryClientState;
        }
        $state = new PdoClientState(new \PDO('sqlite::memory:'));
        $state->migrate();

        return $state;
    }
}
