<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Auth;

/**
 * Crystoline\Xpsr\Auth\UserInterface for an Eloquent model, from its key and its "password"
 * attribute:
 *
 *     final class User extends Model implements UserInterface
 *     {
 *         use Authenticatable;
 *
 *         protected $hidden = ['password'];
 *     }
 *
 * Override authPasswordColumn() for another column, and set the same in auth.users.password.
 */
trait Authenticatable
{
    public function authIdentifier(): string|int
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key) ? $key : (string) $key;
    }

    public function authPasswordHash(): ?string
    {
        $hash = $this->getAttribute($this->authPasswordColumn());

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    protected function authPasswordColumn(): string
    {
        return 'password';
    }
}
