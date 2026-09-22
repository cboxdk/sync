<?php

declare(strict_types=1);

namespace Cbox\Sync\Tests;

use Cbox\Sync\Persistence\Pdo\PdoStore;
use Cbox\Sync\Testing\InteractsWithSync;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use InteractsWithSync;

    /**
     * The shared database the suite was pointed at, emptied, or null when it
     * runs without one. Parity tests add it next to the two local stores, so
     * the identifier edge cases meet MySQL's and PostgreSQL's collations and
     * not only SQLite's.
     */
    public function databaseStore(): ?PdoStore
    {
        $dsn = (string) (getenv('SYNC_DSN') ?: '');

        return $dsn === '' ? null : self::pdoStore($dsn, (string) (getenv('SYNC_DB_USER') ?: ''), (string) (getenv('SYNC_DB_PASSWORD') ?: ''));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSync();
    }
}
