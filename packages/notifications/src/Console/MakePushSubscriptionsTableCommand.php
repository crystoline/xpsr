<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;

/**
 * Writes the migration for the webpush channel's subscriptions, notifications.webpush.table
 * (default push_subscriptions), laid out as Laravel's web push channel's.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakePushSubscriptionsTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:push-subscriptions-table', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the push subscriptions table.');
    }

    protected function option(): string
    {
        return 'notifications.webpush.table';
    }

    protected function defaultTable(): string
    {
        return 'push_subscriptions';
    }

    protected function stub(): string
    {
        return 'migration.push_subscriptions';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.push_subscriptions.stub';
    }

    protected function what(): string
    {
        return 'push subscriptions table';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate, and php xpsr webpush:keys --write if VAPID_PUBLIC_KEY is not set.';
    }
}
