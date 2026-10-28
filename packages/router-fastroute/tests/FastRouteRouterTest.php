<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Router\FastRoute\Tests;

use Crystoline\Xpsr\Contracts\Routing\RouterInterface;
use Crystoline\Xpsr\Router\FastRoute\FastRouteRouter;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Testing\RouterContractTestCase;

final class FastRouteRouterTest extends RouterContractTestCase
{
    protected function createRouter(RouteCollection $routes): RouterInterface
    {
        return new FastRouteRouter($routes);
    }
}
