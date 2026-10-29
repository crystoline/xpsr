<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\MigratorFactory;

/**
 * Writes the migration that creates the table for API tokens (auth.guards driver "token"),
 * named by auth.tokens.table (default api_tokens).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeTokenTableCommand extends TableMigrationCommand
{
    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, Configuration $config, ?Closure $clock = null)
    {
        parent::__construct('make:token-table', $migrators, $config, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the API token table.');
    }

    protected function option(): string
    {
        return 'auth.tokens.table';
    }

    protected function defaultTable(): string
    {
        return 'api_tokens';
    }

    protected function stub(): string
    {
        return 'migration.tokens';
    }

    protected function what(): string
    {
        return 'API token table';
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate, and set AUTH_API_DRIVER=token.';
    }
}
