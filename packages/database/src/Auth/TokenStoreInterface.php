<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\UserInterface;
use DateTimeImmutable;

/**
 * Where a token guard's API tokens are kept: TokenRepository (driver "token") or
 * SanctumTokenRepository (driver "sanctum"). The auth:token commands work with either.
 */
interface TokenStoreInterface
{
    /**
     * @param list<string> $scopes what the token allows; ["*"] for everything
     * @param string|null $guard the guard it is for; the first one with this store's driver without a name
     */
    public function create(UserInterface $user, string $name, array $scopes = ['*'], ?DateTimeImmutable $expiresAt = null, ?string $guard = null): NewAccessToken;

    /** @return list<AccessToken> the user's tokens on a guard, newest first, including expired ones */
    public function forUser(UserInterface $user, ?string $guard = null): array;

    /** Delete a token; false when there was none with this ID. */
    public function revoke(int $id): bool;

    /** Delete every token of a user on a guard, and return how many there were. */
    public function revokeAll(UserInterface $user, ?string $guard = null): int;
}
