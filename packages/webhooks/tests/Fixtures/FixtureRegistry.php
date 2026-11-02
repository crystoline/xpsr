<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests\Fixtures;

use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Clock\MockClock;

/** PSR-17 factories, a fake HTTP client, a recording event dispatcher, and a mock clock, set by Project before boot. */
final class FixtureRegistry extends ServiceRegistry
{
    public static ?FakeClient $client = null;
    public static ?RecordingDispatcher $events = null;
    public static ?MockClock $clock = null;

    public function register(): void
    {
        $factory = new Psr17Factory();
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
        $this->container->instance(RequestFactoryInterface::class, $factory);
        $this->container->instance(ClientInterface::class, self::$client ?? new FakeClient());
        $this->container->instance(EventDispatcherInterface::class, self::$events ?? new RecordingDispatcher());
        $this->container->instance(ClockInterface::class, self::$clock ?? new MockClock('2026-11-02 10:00:00', 'UTC'));
    }
}
