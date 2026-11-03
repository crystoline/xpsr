<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures;

use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Exception\ExceptionHandler;
use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/** PSR-17 factories, the exception handler, a mock clock, and in-memory users, set by Project before boot. */
final class FixtureRegistry extends ServiceRegistry
{
    public static ?MockClock $clock = null;
    public static ?InMemoryUserProvider $users = null;

    public function register(): void
    {
        $factory = new Psr17Factory();
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
        $this->container->instance(RequestFactoryInterface::class, $factory);
        $this->container->instance(ServerRequestFactoryInterface::class, $factory);
        $this->container->factory(ExceptionHandlerInterface::class, static fn(ContainerInterface $container): ExceptionHandler => new ExceptionHandler(
            $container->get(ResponseFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            $container->get(LoggerInterface::class),
            debug: true,
            whoops: false,
        ), shared: true);
        $this->container->instance(LoggerInterface::class, new NullLogger());
        $this->container->instance(ClockInterface::class, self::$clock ?? new MockClock('now', 'UTC'));
        $this->container->instance('test.users', self::$users ?? new InMemoryUserProvider());
    }
}
