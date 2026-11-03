<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests\Fixtures;

use Crystoline\Xpsr\Exception\ExceptionHandler;
use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** PSR-17 factories, a null logger, and the exception handler. */
final class FixtureRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $factory = new Psr17Factory();
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
        $this->container->instance(RequestFactoryInterface::class, $factory);
        $this->container->instance(ServerRequestFactoryInterface::class, $factory);
        $this->container->instance(LoggerInterface::class, new NullLogger());
        $this->container->factory(ExceptionHandlerInterface::class, static fn(ContainerInterface $container): ExceptionHandler => new ExceptionHandler(
            $container->get(ResponseFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            $container->get(LoggerInterface::class),
            whoops: false,
        ), shared: true);
    }
}
