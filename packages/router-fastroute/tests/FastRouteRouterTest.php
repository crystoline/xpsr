<?php

declare(strict_types=1);

namespace Crystoline\Router\FastRoute\Tests;

use Crystoline\Contracts\Routing\RouterInterface;
use Crystoline\Router\FastRoute\FastRouteRouter;
use Crystoline\Routing\RouteCollection;
use Crystoline\Testing\RouterContractTestCase;

final class FastRouteRouterTest extends RouterContractTestCase
{
    protected function createRouter(RouteCollection $routes): RouterInterface
    {
        return new FastRouteRouter($routes);
    }
}
