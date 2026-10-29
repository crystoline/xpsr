<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;
use InvalidArgumentException;

/**
 * Writes the migration for the queue's tables: queue.table (default queue_messages) for
 * database:// transports, and queue.failed.table (default failed_jobs).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeQueueTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, private readonly Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:queue-table', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the queue tables.');
    }

    protected function option(): string
    {
        return 'queue.table';
    }

    protected function defaultTable(): string
    {
        return 'queue_messages';
    }

    protected function stub(): string
    {
        return 'migration.queue';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.queue.stub';
    }

    protected function what(): string
    {
        return 'queue tables';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate, and use a database:// transport, such as QUEUE_DSN=database://default.';
    }

    protected function replacements(): array
    {
        $failed = $this->settings->get('queue.failed.table', 'failed_jobs');
        if (!is_string($failed) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $failed)) {
            throw new InvalidArgumentException('queue.failed.table must be a plain table name: letters, digits, and underscores.');
        }

        return ['{{ failed }}' => var_export($failed, true)];
    }
}
