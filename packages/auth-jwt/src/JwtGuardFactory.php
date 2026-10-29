<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt;

use Crystoline\Xpsr\Auth\GuardFactoryInterface;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Psr\Clock\ClockInterface;

/**
 * The "jwt" guard driver ("auth.driver.jwt").
 *
 * @internal Registered by JwtServiceRegistry.
 */
final class JwtGuardFactory implements GuardFactoryInterface
{
    public function __construct(private readonly JwtOptions $options, private readonly ClockInterface $clock) {}

    public function create(string $name, array $config, UserProviderInterface $users): GuardInterface
    {
        return new JwtGuard($name, $this->options, $users, $this->clock);
    }
}
