<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Auth;

use Crystoline\Xpsr\Auth\UserProviderFactoryInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The "doctrine" user provider ("auth.users.doctrine").
 *
 * @internal Registered by DoctrineOrmServiceRegistry.
 */
final class DoctrineUserProviderFactory implements UserProviderFactoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entities) {}

    public function create(array $config): UserProviderInterface
    {
        return DoctrineUserProvider::fromConfig($this->entities, $config);
    }
}
