<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Router\Sunrise;

use Crystoline\Xpsr\Routing\AbstractRouter;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Routing\RouteDefinition;
use Crystoline\Xpsr\Routing\RouteMatch;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sunrise\Http\Router\Exception\HttpException;
use Sunrise\Http\Router\Loader\LoaderInterface;
use Sunrise\Http\Router\ReferenceResolverInterface;
use Sunrise\Http\Router\Route;
use Sunrise\Http\Router\Router as NativeRouter;

/**
 * Matches XPSR's route definitions with Sunrise's router (sunrise/http-router 3). Sunrise only
 * matches: XPSR runs the handlers and middleware, so the router gets no request handlers to
 * resolve, and nothing of Sunrise's own request handling, loaders, or OpenAPI tooling is used.
 */
final class SunriseRouter extends AbstractRouter
{
    private readonly NativeRouter $router;
    /** @var array<string, RouteDefinition> */
    private array $definitions = [];

    public function __construct(RouteCollection $routes)
    {
        parent::__construct($routes);
        $native = [];
        foreach ($routes->all() as $index => $route) {
            $id = 'route_' . $index;
            $this->definitions[$id] = $route;
            $path = '';
            $patterns = [];
            foreach ($route->pattern()->parts() as $part) {
                if (is_string($part)) {
                    $path .= $part;
                    continue;
                }
                [$name, $regex] = $part;
                $path .= '{' . $name . '}';
                $patterns[$name] = $regex;
            }
            $native[] = new Route($id, $path, null, $patterns, $route->methods);
        }
        $this->router = new NativeRouter(self::unusedResolver(), [self::loader($native)]);
    }

    protected function nativeMatch(ServerRequestInterface $request): ?RouteMatch
    {
        if ($this->definitions === []) {
            // Sunrise refuses to match against an empty route list.
            return null;
        }
        try {
            $route = $this->router->match($request);
        } catch (HttpException) {
            // Not found, method not allowed, or a path that is not UTF-8: no match for this method.
            return null;
        }
        // Sunrise decodes the path before matching, and the shared policy decodes the parameters
        // once more, so the matched values are encoded again here.
        $parameters = array_map(static fn(mixed $value): string => rawurlencode((string) $value), $route->getAttributes());
        return RouteMatch::found($this->definitions[$route->getName()], $parameters);
    }

    /** @param list<Route> $routes */
    private static function loader(array $routes): LoaderInterface
    {
        return new class ($routes) implements LoaderInterface {
            /** @param list<Route> $routes */
            public function __construct(private readonly array $routes) {}

            public function load(): iterable
            {
                return $this->routes;
            }
        };
    }

    private static function unusedResolver(): ReferenceResolverInterface
    {
        return new class implements ReferenceResolverInterface {
            public function resolveMiddleware(mixed $reference): MiddlewareInterface
            {
                throw new LogicException('Sunrise only matches routes; XPSR executes middleware.');
            }

            public function resolveRequestHandler(mixed $reference): RequestHandlerInterface
            {
                throw new LogicException('Sunrise only matches routes; XPSR executes handlers.');
            }
        };
    }
}
