<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Closure;

/**
 * The configured user provider, resolved on first use, so that a guard can be built for a guest
 * without connecting to anything.
 *
 * @internal Used by AuthManager.
 */
final class LazyUserProvider implements UserProviderInterface
{
    private ?UserProviderInterface $provider = null;

    /** @param Closure(): UserProviderInterface $resolve */
    public function __construct(private readonly Closure $resolve) {}

    public function findById(string|int $id): ?UserInterface
    {
        return $this->provider()->findById($id);
    }

    public function findByLogin(string $login): ?UserInterface
    {
        return $this->provider()->findByLogin($login);
    }

    public function updatePasswordHash(UserInterface $user, string $hash): void
    {
        $this->provider()->updatePasswordHash($user, $hash);
    }

    private function provider(): UserProviderInterface
    {
        return $this->provider ??= ($this->resolve)();
    }
}
