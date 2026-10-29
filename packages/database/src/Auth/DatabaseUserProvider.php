<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Users from a table (auth.users driver "database"), returned as GenericUser objects with every
 * column except the password: $user->get('name').
 *
 *   auth.users = ['driver' => 'database', 'table' => 'users', 'id' => 'id',
 *                 'login' => 'email', 'password' => 'password', 'connection' => null]
 *
 * Logins are compared exactly as stored; store email addresses in lowercase and lowercase them
 * at sign-in (the Lowercase input filter does).
 */
final class DatabaseUserProvider implements UserProviderInterface
{
    private const NAME = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'users',
        private readonly string $idColumn = 'id',
        private readonly string $loginColumn = 'email',
        private readonly string $passwordColumn = 'password',
    ) {
        foreach (['table' => $table, 'id' => $idColumn, 'login' => $loginColumn, 'password' => $passwordColumn] as $option => $name) {
            if (!preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException("auth.users.{$option} must be a plain table or column name: letters, digits, and underscores.");
            }
        }
    }

    public function findById(string|int $id): ?UserInterface
    {
        return $this->findBy($this->idColumn, $id);
    }

    public function findByLogin(string $login): ?UserInterface
    {
        return $login === '' ? null : $this->findBy($this->loginColumn, $login);
    }

    public function updatePasswordHash(UserInterface $user, string $hash): void
    {
        $this->connection->update($this->table, [$this->passwordColumn => $hash], [$this->idColumn => $user->authIdentifier()]);
    }

    private function findBy(string $column, string|int $value): ?GenericUser
    {
        $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE {$column} = ?", [$value]);
        if ($row === false) {
            return null;
        }
        $row = array_change_key_case($row, CASE_LOWER);
        $id = $row[strtolower($this->idColumn)] ?? null;
        $hash = $row[strtolower($this->passwordColumn)] ?? null;
        unset($row[strtolower($this->passwordColumn)]);
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        // Drivers return integer keys as strings or numbers; sessions and tokens see the same ID either way.
        return new GenericUser(is_string($id) && ctype_digit($id) && $id[0] !== '0' ? (int) $id : $id, is_string($hash) ? $hash : null, $row);
    }
}
