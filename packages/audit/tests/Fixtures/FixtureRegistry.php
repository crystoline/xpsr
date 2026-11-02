<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures;

use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Clock\MockClock;

/** PSR-17 factories, a mock clock, the current identity, and a request ID holder, set by Project before boot. */
final class FixtureRegistry extends ServiceRegistry
{
    public static ?MockClock $clock = null;
    public static ?CurrentIdentity $identity = null;
    public static ?RequestId $requestId = null;

    public function register(): void
    {
        $factory = new Psr17Factory();
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
        $this->container->instance(RequestFactoryInterface::class, $factory);
        $this->container->instance(ServerRequestFactoryInterface::class, $factory);
        $this->container->instance(ClockInterface::class, self::$clock ?? new MockClock('2026-11-02 10:00:00', 'UTC'));
        $this->container->instance(CurrentIdentity::class, self::$identity ?? new CurrentIdentity());
        $this->container->instance(RequestId::class, self::$requestId ?? new RequestId());
    }
}
