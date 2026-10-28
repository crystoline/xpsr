<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Migration;

/**
 * Runs and rolls back migrations for one connection. The migrate, migrate:rollback, and
 * migrate:status commands use only this interface, so a package can provide another runner,
 * such as one for Doctrine Migrations, and the commands stay the same.
 *
 * Every method throws MigrationException when a migration cannot be loaded, run, or rolled
 * back. Prove an implementation with MigratorContractTestCase.
 */
interface MigratorInterface
{
    /**
     * Every known migration, oldest first. "missing" means it has run but its source is gone.
     * A runner without batches reports null for "batch".
     *
     * @return list<array{migration: string, status: 'ran'|'pending'|'missing', batch: int|null}>
     */
    public function status(): array;

    /** @return list<string> the migrations migrate() would run, in order */
    public function pending(): array;

    /**
     * Run every pending migration, oldest first, and stop at the first failure. Migrations that
     * completed before a failure stay applied and recorded.
     *
     * @param (callable(string): void)|null $progress called with each name before it runs
     * @return list<string> the migrations that ran
     */
    public function migrate(?callable $progress = null): array;

    /**
     * Roll back the last batch (for a runner without batches, the last migration), or the last
     * $steps migrations, newest first.
     *
     * @param int|null $steps at least 1
     * @param (callable(string): void)|null $progress called with each name before it is rolled back
     * @return list<string> the migrations that were rolled back
     */
    public function rollback(?int $steps = null, ?callable $progress = null): array;
}
