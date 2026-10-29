<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * Builds the guards of one driver. A package registers its factory as the service
 * "auth.driver.<driver>", for example "auth.driver.jwt".
 */
interface GuardFactoryInterface
{
    /** @param array<array-key, mixed> $config the guard's entry in auth.guards */
    public function create(string $name, array $config, UserProviderInterface $users): GuardInterface;
}
