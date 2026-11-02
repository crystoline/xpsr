<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;
use InvalidArgumentException;

/**
 * Writes the migration for the endpoints and deliveries tables, webhooks.endpoints_table and
 * webhooks.deliveries_table.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeWebhooksTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, private readonly Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:webhooks-table', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the webhook endpoints and deliveries tables.');
    }

    protected function option(): string
    {
        return 'webhooks.endpoints_table';
    }

    protected function defaultTable(): string
    {
        return 'webhook_endpoints';
    }

    protected function stub(): string
    {
        return 'migration.webhooks';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.webhooks.stub';
    }

    protected function what(): string
    {
        return 'webhook tables';
    }

    protected function replacements(): array
    {
        $deliveries = $this->settings->get('webhooks.deliveries_table', 'webhook_deliveries');
        if (!is_string($deliveries) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $deliveries)) {
            throw new InvalidArgumentException('webhooks.deliveries_table must be a plain table name: letters, digits, and underscores.');
        }

        return ['{{ deliveries }}' => var_export($deliveries, true), '{{ deliveries_prefix }}' => substr($deliveries, 0, 40)];
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate.';
    }
}
