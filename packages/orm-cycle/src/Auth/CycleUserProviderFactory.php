<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Auth;

use Crystoline\Xpsr\Auth\UserProviderFactoryInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;

/**
 * The "cycle" user provider ("auth.users.cycle").
 *
 * @internal Registered by CycleOrmServiceRegistry.
 */
final class CycleUserProviderFactory implements UserProviderFactoryInterface
{
    public function __construct(private readonly ORMInterface $orm, private readonly EntityManagerInterface $entities) {}

    public function create(array $config): UserProviderInterface
    {
        return CycleUserProvider::fromConfig($this->orm, $this->entities, $config);
    }
}
