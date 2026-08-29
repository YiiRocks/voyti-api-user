<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\tests\Support;

use YiiRocks\Voyti\Api\tests\TestCase;

/**
 * Test case that boots the in-memory SQLite database (module tables) for each test.
 */
abstract class DatabaseTestCase extends TestCase
{
    use DatabaseSetupTrait;

    protected function setUp(): void
    {
        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }
}
