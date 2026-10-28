<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\ClockInterface as SymfonyClockInterface;
use Symfony\Component\Clock\NativeClock;
use Tests\TestCase;

/** CORS through the skeleton's full HTTP kernel, and the application clock. */
final class CorsAndClockTest extends TestCase
{
    public function testTheSkeletonAnswersCorsRequestsOnceAnOriginIsAllowed(): void
    {
        $this->application()->getConfig()->set('cors.allowed_origins', 'https://app.example.com');

        $preflight = $this->request('OPTIONS', '/hello', ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET']);
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('https://app.example.com', $preflight->getHeaderLine('Access-Control-Allow-Origin'));

        $hello = $this->get('/hello', ['Origin' => 'https://app.example.com']);
        self::assertSame(200, $hello->getStatusCode());
        self::assertSame('https://app.example.com', $hello->getHeaderLine('Access-Control-Allow-Origin'));

        $missing = $this->get('/missing', ['Origin' => 'https://app.example.com', 'Accept' => 'application/json']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('https://app.example.com', $missing->getHeaderLine('Access-Control-Allow-Origin'), 'Error responses are readable by the browser.');

        $other = $this->get('/hello', ['Origin' => 'https://evil.test']);
        self::assertFalse($other->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testCorsIsOffByDefault(): void
    {
        $response = $this->get('/hello', ['Origin' => 'https://app.example.com']);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function testTheClockUsesTheApplicationTimeZone(): void
    {
        $clock = $this->application()->get(ClockInterface::class);
        self::assertInstanceOf(NativeClock::class, $clock);
        self::assertSame($clock, $this->application()->get(SymfonyClockInterface::class));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    public function testFreezeTimeStopsTheClockUntilMoved(): void
    {
        $clock = $this->freezeTime('2026-01-31 09:00:00');
        $app = $this->application();
        self::assertSame($clock, $app->get(ClockInterface::class));
        self::assertSame($clock, $app->get(SymfonyClockInterface::class));
        self::assertSame('2026-01-31 09:00:00', $app->get(ClockInterface::class)->now()->format('Y-m-d H:i:s'));
        $clock->modify('+90 minutes');
        self::assertSame('2026-01-31 10:30:00', $app->get(ClockInterface::class)->now()->format('Y-m-d H:i:s'));
    }

    public function testAnInvalidTimeZoneIsReported(): void
    {
        $fresh = new Application(dirname(__DIR__, 2));
        $fresh->getConfig()->set('app.timezone', 'Mars/Olympus');
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('app.timezone must be a time zone identifier such as UTC or Europe/Berlin.');
        $fresh->get(ClockInterface::class);
    }
}
