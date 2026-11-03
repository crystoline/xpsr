<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Router\Sunrise\Tests;

use Crystoline\Xpsr\Contracts\Routing\RouterInterface;
use Crystoline\Xpsr\Router\Sunrise\SunriseRouter;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Testing\RouterContractTestCase;
use Nyholm\Psr7\ServerRequest;

final class SunriseRouterTest extends RouterContractTestCase
{
    protected function createRouter(RouteCollection $routes): RouterInterface
    {
        return new SunriseRouter($routes);
    }

    public function testAnEmptyCollectionMatchesNothing(): void
    {
        $match = (new SunriseRouter(new RouteCollection()))->match(new ServerRequest('GET', '/anything'));

        self::assertNull($match->route);
        self::assertSame([], $match->allowedMethods);
    }
}
