<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthOptions;
use Crystoline\Xpsr\Auth\Jwt\Console\IssueJwtCommand;
use Crystoline\Xpsr\Auth\Jwt\Console\JwtSecretCommand;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * Registers the "jwt" guard driver for config/auth.php ("auth.driver.jwt"), JwtIssuer for
 * sign-in endpoints, and the auth:jwt-secret and auth:jwt commands. Options live in auth.jwt.
 * List it in app.registry after Crystoline\Xpsr\Registry\AuthServiceRegistry.
 */
final class JwtServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(JwtOptions::class, static function (ContainerInterface $container): JwtOptions {
            $url = $container->get(Configuration::class)->get('app.url', '');

            return JwtOptions::fromArray(
                $container->get(AuthOptions::class)->jwt,
                $container->get(Application::class)->getRootDir(),
                is_string($url) ? rtrim($url, '/') : '',
            );
        }, shared: true);
        $this->container->factory(JwtIssuer::class, static fn(ContainerInterface $container): JwtIssuer => new JwtIssuer(
            $container->get(JwtOptions::class),
            $container->get(ClockInterface::class),
        ), shared: true);
        $this->container->factory('auth.driver.jwt', static fn(ContainerInterface $container): JwtGuardFactory => new JwtGuardFactory(
            $container->get(JwtOptions::class),
            $container->get(ClockInterface::class),
        ), shared: true);
        $this->container->bind(JwtSecretCommand::class);
        $this->container->bind(IssueJwtCommand::class);
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $commands = $this->container->get(CommandRegistry::class);
            $commands->register('auth:jwt-secret', JwtSecretCommand::class, 'Make a secret for signing JWTs.');
            $commands->register('auth:jwt', IssueJwtCommand::class, 'Issue a JWT for a user.');
        }
    }
}
