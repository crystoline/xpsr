<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;

/**
 * Writes the migration for the database lock store's table, lock.database.table (default
 * lock_keys), laid out as Symfony Lock's DoctrineDbalStore expects.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeLockTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:lock-table', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the lock table.');
    }

    protected function option(): string
    {
        return 'lock.database.table';
    }

    protected function defaultTable(): string
    {
        return 'lock_keys';
    }

    protected function stub(): string
    {
        return 'migration.lock';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.lock.stub';
    }

    protected function what(): string
    {
        return 'lock table';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate, and set lock.store to database (LOCK_STORE=database).';
    }
}
