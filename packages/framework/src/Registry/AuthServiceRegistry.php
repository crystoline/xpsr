<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Auth\Access\Gate;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\AuthOptions;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Auth\Http\Guest;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Registers authentication and authorization, configured in config/auth.php:
 *
 *   Crystoline\Xpsr\Auth\AuthManager       sign-in, sign-out, guards, and the user provider
 *   Crystoline\Xpsr\Auth\Access\Gate       policies
 *   Crystoline\Xpsr\Auth\PasswordHasher    password hashes
 *   Crystoline\Xpsr\Auth\Http\Authenticate the default guard's middleware, and for each guard
 *                                          Authenticate::guard($name) and Authenticate::optional($name)
 *   Crystoline\Xpsr\Auth\Http\Guest        for pages only guests need, and Guest::guard($name)
 *
 * The jwt and token guard drivers, and the database and doctrine user providers, come from their
 * packages, which register them as "auth.driver.<driver>" and "auth.users.<driver>".
 */
final class AuthServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(AuthOptions::class, static function (ContainerInterface $container): AuthOptions {
            return AuthOptions::fromArray(self::config($container));
        }, shared: true);
        $this->container->singleton(PasswordHasher::class);
        $this->container->singleton(CurrentIdentity::class);
        $this->container->factory(AuthManager::class, static fn(ContainerInterface $container): AuthManager => new AuthManager(
            $container,
            $container->get(AuthOptions::class),
            $container->get(PasswordHasher::class),
            $container->get(CurrentIdentity::class),
        ), shared: true);
        $this->container->factory(Gate::class, static fn(ContainerInterface $container): Gate => new Gate(
            $container,
            $container->get(AuthOptions::class)->policies,
        ), shared: true);

        $this->container->factory(Authenticate::class, self::middleware(null, true), shared: true);
        $this->container->factory(Authenticate::optional(), self::middleware(null, false), shared: true);
        $guards = $this->container->get(Configuration::class)->get('auth.guards', ['web' => [], 'api' => []]);
        foreach (is_array($guards) ? array_keys($guards) : [] as $name) {
            if (is_string($name)) {
                $this->container->factory(Authenticate::guard($name), self::middleware($name, true), shared: true);
                $this->container->factory(Authenticate::optional($name), self::middleware($name, false), shared: true);
                $this->container->factory(Guest::guard($name), self::guest($name), shared: true);
            }
        }
        $this->container->factory(Guest::class, self::guest(null), shared: true);
    }

    /** @return \Closure(ContainerInterface): Guest */
    private static function guest(?string $guard): \Closure
    {
        return static fn(ContainerInterface $container): Guest => new Guest(
            $container->get(AuthManager::class),
            $container->get(ResponseFactoryInterface::class),
            $guard,
        );
    }

    /** @return \Closure(ContainerInterface): Authenticate */
    private static function middleware(?string $guard, bool $required): \Closure
    {
        return static fn(ContainerInterface $container): Authenticate => new Authenticate(
            $container->get(AuthManager::class),
            $container->get(CurrentIdentity::class),
            $container->get(ResponseFactoryInterface::class),
            $guard,
            $required,
        );
    }

    /** @return array<array-key, mixed> */
    private static function config(ContainerInterface $container): array
    {
        $config = $container->get(Configuration::class)->get('auth', []);
        if (!is_array($config)) {
            throw new InvalidArgumentException('The auth configuration must be an array.');
        }

        return $config;
    }
}
