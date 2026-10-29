<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Auth;

use Crystoline\Xpsr\Auth\UserProviderFactoryInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;

/**
 * The "eloquent" user provider ("auth.users.eloquent").
 *
 * @internal Registered by EloquentServiceRegistry.
 */
final class EloquentUserProviderFactory implements UserProviderFactoryInterface
{
    public function create(array $config): UserProviderInterface
    {
        return EloquentUserProvider::fromConfig($config);
    }
}
