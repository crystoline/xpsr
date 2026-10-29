<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\GuardFactoryInterface;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;

/**
 * The "sanctum" guard driver ("auth.driver.sanctum").
 *
 * @internal Registered by DatabaseServiceRegistry.
 */
final class SanctumGuardFactory implements GuardFactoryInterface
{
    public function __construct(private readonly SanctumTokenRepository $tokens) {}

    public function create(string $name, array $config, UserProviderInterface $users): GuardInterface
    {
        return new SanctumGuard($name, $this->tokens, $users);
    }
}
