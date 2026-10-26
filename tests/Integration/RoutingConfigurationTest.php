<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Application;
use Crystoline\Contracts\Routing\RouterInterface;
use Crystoline\Contracts\Routing\UrlGeneratorInterface;
use Crystoline\Router\FastRoute\FastRouteRouter;
use Crystoline\Router\Sunrise\SunriseRouter;
use Crystoline\ServiceContainer\ContainerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoutingConfigurationTest extends TestCase
{
    public static function adapters(): iterable
    {
        yield 'FastRoute alias' => ['fastroute', FastRouteRouter::class];
        yield 'Sunrise alias' => ['sunrise', SunriseRouter::class];
        yield 'service class' => [SunriseRouter::class, SunriseRouter::class];
    }

    #[DataProvider('adapters')]
    public function testApplicationCanSwitchAdaptersWithoutChangingRoutes(string $adapter, string $expected): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getConfig()->set('routing.adapter', $adapter);
        $app->boot();
        self::assertInstanceOf($expected, $app->get(RouterInterface::class));
        self::assertSame($app->get(RouterInterface::class), $app->get(RouterInterface::class));
        self::assertSame('/hello', $app->get(UrlGeneratorInterface::class)->generate('hello'));
        self::assertSame('/', $app->get(UrlGeneratorInterface::class)->generate('home'));
        $response = $app->getHttpKernel()->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/hello'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['helloooooooo'], json_decode((string) $response->getBody(), true));
    }

    public function testUnknownAdapterFailsDuringKernelConstruction(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getConfig()->set('routing.adapter', 'unknown');
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('routing.adapter');
        $app->getHttpKernel();
    }

    public function testMissingRouteFileFailsClearly(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getConfig()->set('routing.files', ['routes/does-not-exist.php']);
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('route file does not exist');
        $app->getHttpKernel();
    }
}
