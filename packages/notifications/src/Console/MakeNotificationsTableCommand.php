<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;

/**
 * Writes the migration for the database channel's table, notifications.database.table (default
 * notifications), laid out as Laravel's.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeNotificationsTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:notifications-table', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the notifications table.');
    }

    protected function option(): string
    {
        return 'notifications.database.table';
    }

    protected function defaultTable(): string
    {
        return 'notifications';
    }

    protected function stub(): string
    {
        return 'migration.notifications';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.notifications.stub';
    }

    protected function what(): string
    {
        return 'notifications table';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate.';
    }
}
