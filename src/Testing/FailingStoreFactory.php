<?php

declare(strict_types=1);

namespace Cbox\Sync\Testing;

use Cbox\Sync\Contracts\Store;

/**
 * A store that can be made to fail its next commit, on whichever adapter the
 * suite is running against.
 *
 * SYNC_STORE selects it the same way the rest of the suite is selected, so the
 * rollback test runs against a real database in CI rather than only against an
 * array.
 */
class FailingStoreFactory
{
    public static function make(): Store
    {
        if ((getenv('SYNC_STORE') ?: 'memory') === 'memory' || (getenv('SYNC_STORE') ?: '') === 'rehydrating') {
            return new FailingStore;
        }

        $dsn = (string) (getenv('SYNC_DSN') ?: '');
        $store = $dsn === ''
            ? new FailingPdoStore(new \PDO('sqlite::memory:'))
            : new FailingPdoStore(new \PDO($dsn, (string) (getenv('SYNC_DB_USER') ?: ''), (string) (getenv('SYNC_DB_PASSWORD') ?: '')));
        $store->migrate();

        return $store;
    }
}
