<?php

declare(strict_types=1);

namespace Crystoline\Router\Sunrise\Tests;

use Crystoline\Contracts\Routing\RouterInterface;
use Crystoline\Router\Sunrise\SunriseRouter;
use Crystoline\Routing\RouteCollection;
use Crystoline\Testing\RouterContractTestCase;

final class SunriseRouterTest extends RouterContractTestCase
{
    protected function createRouter(RouteCollection $routes): RouterInterface
    {
        return new SunriseRouter($routes);
    }
}
