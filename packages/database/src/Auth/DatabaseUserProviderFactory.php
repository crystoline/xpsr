<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\UserProviderFactoryInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Crystoline\Xpsr\Database\ConnectionManager;
use InvalidArgumentException;

/**
 * The "database" user provider ("auth.users.database").
 *
 * @internal Registered by DatabaseServiceRegistry.
 */
final class DatabaseUserProviderFactory implements UserProviderFactoryInterface
{
    private const OPTIONS = ['driver', 'table', 'id', 'login', 'password', 'connection'];

    public function __construct(private readonly ConnectionManager $connections) {}

    public function create(array $config): UserProviderInterface
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::OPTIONS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option auth.users.%s for the database driver. Known options: %s.', $key, implode(', ', self::OPTIONS)));
            }
        }
        $names = [];
        foreach (['table' => 'users', 'id' => 'id', 'login' => 'email', 'password' => 'password'] as $key => $default) {
            $value = $config[$key] ?? $default;
            if (!is_string($value)) {
                throw new InvalidArgumentException("auth.users.{$key} must be a table or column name.");
            }
            $names[$key] = $value;
        }
        $connection = $config['connection'] ?? null;
        if ($connection !== null && !is_string($connection)) {
            throw new InvalidArgumentException('auth.users.connection must name a connection in config/database.php, or be null for the default.');
        }

        return new DatabaseUserProvider($this->connections->connection($connection), $names['table'], $names['id'], $names['login'], $names['password']);
    }
}
