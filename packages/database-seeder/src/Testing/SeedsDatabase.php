<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Testing;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\Seeder\SeedReport;
use Crystoline\Xpsr\Database\Seeder\SeedRunner;

/**
 * For application tests: load seed data inside the test. Beside RefreshDatabase, which rolls
 * every test back, the rows are gone again when the test ends:
 *
 *   use RefreshDatabase;
 *   use SeedsDatabase;
 *
 *   protected function setUp(): void
 *   {
 *       $this->seed();                          // the default set
 *       $this->seed('demo');                    // a set by name, after the sets it requires
 *       $this->seed(OrdersScenario::class);     // one seeder, which need not be in a set
 *   }
 *
 * RefreshDatabase runs before setUp(), so the tables are there. Use it in a test case that has
 * the skeleton's application() method.
 */
trait SeedsDatabase
{
    abstract protected function application(): Application;

    /** Loads the default set, a set by its name, or one seeder by its class. */
    protected function seed(?string $setOrSeeder = null): SeedReport
    {
        $runner = $this->application()->get(SeedRunner::class);
        if ($setOrSeeder === null) {
            return $runner->run();
        }

        return isset($runner->options()->sets[$setOrSeeder]) || !class_exists($setOrSeeder)
            ? $runner->run([$setOrSeeder])
            : $runner->runSeeders([$setOrSeeder]);
    }
}
