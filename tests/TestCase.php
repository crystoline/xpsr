<?php

declare(strict_types=1);

namespace Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Testing\ApplicationTestCase;

/**
 * Base class for application tests: requests through the HTTP kernel that come back with
 * assertions (get(), postJson(), ...), console commands, sessions, actingAs(), swap(), and
 * freezeTime() come from ApplicationTestCase. See docs/TESTING.md. Helpers that this
 * application's tests share belong here.
 */
abstract class TestCase extends ApplicationTestCase
{
    protected function createApplication(): Application
    {
        return require dirname(__DIR__) . '/bootstrap/app.php';
    }
}
