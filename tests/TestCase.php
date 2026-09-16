<?php

declare(strict_types=1);

namespace Cbox\Sync\Tests;

use Cbox\Sync\Testing\InteractsWithSync;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use InteractsWithSync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSync();
    }
}
