<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\MigratorFactory;

/**
 * Writes the migration that creates the table for session.store "database", named by
 * session.database.table (default sessions).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeSessionTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, Configuration $config, ?Closure $clock = null)
    {
        parent::__construct('make:session-table', $migrators, $config, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the database session table.');
    }

    protected function option(): string
    {
        return 'session.database.table';
    }

    protected function defaultTable(): string
    {
        return 'sessions';
    }

    protected function stub(): string
    {
        return 'migration.sessions';
    }

    protected function what(): string
    {
        return 'session table';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate, and set SESSION_STORE=database.';
    }
}
