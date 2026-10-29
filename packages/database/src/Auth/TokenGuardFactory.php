<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\GuardFactoryInterface;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;

/**
 * The "token" guard driver ("auth.driver.token").
 *
 * @internal Registered by DatabaseServiceRegistry.
 */
final class TokenGuardFactory implements GuardFactoryInterface
{
    public function __construct(private readonly TokenRepository $tokens) {}

    public function create(string $name, array $config, UserProviderInterface $users): GuardInterface
    {
        return new TokenGuard($name, $this->tokens, $users);
    }
}
