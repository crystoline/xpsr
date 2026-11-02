<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;

/**
 * Writes the migration for the log's table, audit.table (default audit_log).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeAuditTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:audit-table', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the audit log table.');
    }

    protected function option(): string
    {
        return 'audit.table';
    }

    protected function defaultTable(): string
    {
        return 'audit_log';
    }

    protected function stub(): string
    {
        return 'migration.audit';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.audit.stub';
    }

    protected function what(): string
    {
        return 'audit log table';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate.';
    }
}
