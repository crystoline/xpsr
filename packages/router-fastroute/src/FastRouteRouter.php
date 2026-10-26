<?php

declare(strict_types=1);

namespace Crystoline\Router\FastRoute;

use Crystoline\Routing\AbstractRouter;
use Crystoline\Routing\RouteCollection;
use Crystoline\Routing\RouteMatch;
use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use Psr\Http\Message\ServerRequestInterface;

final class FastRouteRouter extends AbstractRouter
{
    private readonly Dispatcher $dispatcher;

    public function __construct(RouteCollection $routes)
    {
        parent::__construct($routes);
        $data = new DataGenerator();
        foreach ($routes->all() as $route) {
            foreach ($route->methods as $method) {
                // Prefix internal methods to disable FastRoute's native HEAD fallback.
                $data->addRoute('XPSR_' . $method, $route->pattern()->parts(), $route);
            }
        }
        $this->dispatcher = new GroupCountBased($data->getData());
    }

    protected function nativeMatch(ServerRequestInterface $request): ?RouteMatch
    {
        $result = $this->dispatcher->dispatch('XPSR_' . $request->getMethod(), $request->getUri()->getPath());
        return $result[0] === Dispatcher::FOUND ? RouteMatch::found($result[1], $result[2]) : null;
    }
}
