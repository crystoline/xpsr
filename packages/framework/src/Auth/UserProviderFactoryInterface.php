<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * Builds the user provider of one driver from auth.users. A package registers it as the service
 * "auth.users.<driver>", for example "auth.users.database".
 */
interface UserProviderFactoryInterface
{
    /** @param array<array-key, mixed> $config auth.users */
    public function create(array $config): UserProviderInterface;
}
