<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * Finds users: by ID for sessions and tokens, by login (such as an email address) for sign-in.
 * Configured in auth.users; crystoline/xpsr-database and crystoline/xpsr-orm-doctrine provide
 * implementations, and an application can write its own.
 */
interface UserProviderInterface
{
    public function findById(string|int $id): ?UserInterface;

    public function findByLogin(string $login): ?UserInterface;

    /**
     * Store a new hash for the user's unchanged password, after PasswordHasher found the old one
     * outdated at sign-in. A provider that cannot store it may do nothing.
     */
    public function updatePasswordHash(UserInterface $user, string $hash): void;
}
