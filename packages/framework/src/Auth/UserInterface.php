<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * A user who can sign in. Implement it on your user class or entity; the database user provider
 * returns DatabaseUser objects instead.
 */
interface UserInterface
{
    /** The user's primary key, as sessions and tokens record it. */
    public function authIdentifier(): string|int;

    /** The password hash, or null for a user who cannot sign in with a password. */
    public function authPasswordHash(): ?string;
}
