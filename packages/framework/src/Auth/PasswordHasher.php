<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use InvalidArgumentException;

/**
 * Hashes passwords with PHP's password_hash(): Argon2id when PHP was built with it, otherwise
 * bcrypt. Hashes made with another algorithm or older settings are reported by needsRehash(), and
 * AuthManager replaces them at the next successful sign-in.
 */
final class PasswordHasher
{
    /** Longer passwords are refused, so that hashing cannot be used to tie up the server. */
    public const MAX_LENGTH = 4096;

    private ?string $dummy = null;

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if (strlen($password) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A password must be at most ' . self::MAX_LENGTH . ' bytes.');
        }

        return password_hash($password, self::algorithm());
    }

    /**
     * Whether the password matches the hash. A null hash never matches, but takes as long as a
     * real check, so that responses do not reveal which logins exist.
     */
    public function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || strlen($password) > self::MAX_LENGTH) {
            password_verify($password, $this->dummy ??= password_hash(bin2hex(random_bytes(16)), self::algorithm()));

            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** PASSWORD_ARGON2ID or PASSWORD_BCRYPT. */
    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
