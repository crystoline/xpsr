<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Console\TableMigrationCommand;
use Crystoline\Xpsr\Database\MigratorFactory;
use InvalidArgumentException;

/**
 * Writes the migration for the clients, access tokens, refresh tokens, and authorization codes
 * tables, named by oauth.tables.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeOAuthTablesCommand extends TableMigrationCommand
{
    private const OTHERS = ['access_tokens' => 'oauth_access_tokens', 'refresh_tokens' => 'oauth_refresh_tokens', 'auth_codes' => 'oauth_auth_codes'];

    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(MigratorFactory $migrators, private readonly Configuration $settings, ?Closure $clock = null)
    {
        parent::__construct('make:oauth-tables', $migrators, $settings, $clock);
    }

    protected function configure(): void
    {
        $this->setDescription('Create the migration for the OAuth2 clients, tokens, and codes tables.');
    }

    protected function option(): string
    {
        return 'oauth.tables.clients';
    }

    protected function defaultTable(): string
    {
        return 'oauth_clients';
    }

    protected function stub(): string
    {
        return 'migration.oauth';
    }

    protected function stubPath(): string
    {
        return dirname(__DIR__) . '/Resources/migration.oauth.stub';
    }

    protected function what(): string
    {
        return 'OAuth2 tables';
    }

    protected function replacements(): array
    {
        $values = [];
        foreach (self::OTHERS as $key => $default) {
            $name = $this->settings->get("oauth.tables.{$key}", $default);
            if (!is_string($name) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $name)) {
                throw new InvalidArgumentException("oauth.tables.{$key} must be a plain table name: letters, digits, and underscores.");
            }
            $values["{{ {$key} }}"] = var_export($name, true);
            // For index names, inside quotes in the stub.
            $values["{{ {$key}_prefix }}"] = substr($name, 0, 40);
        }

        return $values;
    }

    protected function nextStep(): string
    {
        return 'Next: run php xpsr migrate, then php xpsr oauth:keys --write.';
    }
}
