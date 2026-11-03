<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use InvalidArgumentException;

/**
 * Hashes passwords with PHP's password_hash(). The algorithm is auth.password.algorithm: auto
 * (Argon2id when PHP was built with it, otherwise bcrypt), argon2id, or bcrypt, which keeps the
 * hashes readable by a Laravel application on the same users table. Hashes made with another
 * algorithm or other options are reported by needsRehash(), and AuthManager replaces them at the
 * next successful sign-in, so a changed setting takes effect user by user.
 */
final class PasswordHasher
{
    /** Longer passwords are refused, so that hashing cannot be used to tie up the server. */
    public const MAX_LENGTH = 4096;
    public const AUTO = 'auto';
    /** The values auth.password.algorithm accepts. */
    public const ALGORITHMS = [self::AUTO, 'argon2id', 'bcrypt'];

    /** The password_hash() algorithm constant. */
    private readonly string $algorithm;
    private ?string $dummy = null;

    /**
     * @param string $algorithm auto, argon2id, or bcrypt
     * @param array<string, int> $options password_hash() options: cost for bcrypt; memory_cost,
     *                                    time_cost, and threads for Argon2id (PHP's defaults when unset)
     */
    public function __construct(string $algorithm = self::AUTO, private readonly array $options = [])
    {
        $this->algorithm = match ($algorithm) {
            self::AUTO => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
            'bcrypt' => PASSWORD_BCRYPT,
            'argon2id' => defined('PASSWORD_ARGON2ID')
                ? PASSWORD_ARGON2ID
                : throw new InvalidArgumentException('auth.password.algorithm is argon2id, but this PHP was built without Argon2; use auto or bcrypt.'),
            default => throw new InvalidArgumentException("auth.password.algorithm must be auto, argon2id, or bcrypt, not \"{$algorithm}\"."),
        };
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if (strlen($password) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A password must be at most ' . self::MAX_LENGTH . ' bytes.');
        }

        return password_hash($password, $this->algorithm, $this->options);
    }

    /**
     * Whether the password matches the hash. A null hash never matches, but takes as long as a
     * real check, so that responses do not reveal which logins exist.
     */
    public function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || strlen($password) > self::MAX_LENGTH) {
            password_verify($password, $this->dummy ??= password_hash(bin2hex(random_bytes(16)), $this->algorithm, $this->options));

            return false;
        }

        return password_verify($password, $hash);
    }

    /** Whether the hash was made with another algorithm or other options than the setting. */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /** The password_hash() algorithm in use: PASSWORD_ARGON2ID or PASSWORD_BCRYPT. */
    public function algorithm(): string
    {
        return $this->algorithm;
    }
}
