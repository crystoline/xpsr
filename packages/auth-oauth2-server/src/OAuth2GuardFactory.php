<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Crystoline\Xpsr\Auth\GuardFactoryInterface;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use League\OAuth2\Server\ResourceServer;
use Psr\Container\ContainerInterface;

/**
 * The "oauth2" guard driver ("auth.driver.oauth2").
 *
 * @internal Registered by OAuth2ServiceRegistry.
 */
final class OAuth2GuardFactory implements GuardFactoryInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function create(string $name, array $config, UserProviderInterface $users): GuardInterface
    {
        $container = $this->container;

        return new OAuth2Guard(
            $name,
            // The key file is read when the first token arrives, not for every guest.
            static fn(): ResourceServer => $container->get(ResourceServer::class),
            $container->get(Clients::class),
            $users,
        );
    }
}
