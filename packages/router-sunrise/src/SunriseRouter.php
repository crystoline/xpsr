<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Router\Sunrise;

use Crystoline\Xpsr\Routing\AbstractRouter;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Routing\RouteDefinition;
use Crystoline\Xpsr\Routing\RouteMatch;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sunrise\Http\Router\Exception\MethodNotAllowedException;
use Sunrise\Http\Router\Exception\RouteNotFoundException;
use Sunrise\Http\Router\Route;
use Sunrise\Http\Router\Router as NativeRouter;

final class SunriseRouter extends AbstractRouter
{
    private readonly NativeRouter $router;
    /** @var array<string, RouteDefinition> */
    private array $definitions = [];

    public function __construct(RouteCollection $routes)
    {
        parent::__construct($routes);
        $this->router = new NativeRouter();
        $unusedHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new LogicException('Sunrise only matches routes; XPSR executes handlers.');
            }
        };
        foreach ($routes->all() as $index => $route) {
            $id = 'route_' . $index;
            $this->definitions[$id] = $route;
            $path = implode('', array_map(
                static fn($part) => is_string($part) ? $part : '{' . $part[0] . '<' . $part[1] . '>}',
                $route->pattern()->parts(),
            ));
            $this->router->addRoute(new Route($id, $path, $route->methods, $unusedHandler));
        }
    }

    protected function nativeMatch(ServerRequestInterface $request): ?RouteMatch
    {
        try {
            $route = $this->router->match($request);
            return RouteMatch::found($this->definitions[$route->getName()], $route->getAttributes());
        } catch (MethodNotAllowedException|RouteNotFoundException) {
            return null;
        }
    }
}
