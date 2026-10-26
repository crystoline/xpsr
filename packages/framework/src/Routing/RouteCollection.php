<?php

declare(strict_types=1);

namespace Crystoline\Routing;

use InvalidArgumentException;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;

final class RouteCollection
{
    /** @var list<RouteDefinition> */
    private array $routes = [];
    private bool $frozen = false;
    private string $prefix = '';
    private string $namePrefix = '';
    private array $middleware = [];

    /** @param array<array-key, mixed> $methods Validated at the registration boundary. */
    public function add(array $methods, string $path, mixed $handler): RouteDefinition
    {
        $this->assertMutable();
        new RoutePattern($path);
        $route = new RouteDefinition(array_values($methods), $this->prefix . $path, $handler, $this->namePrefix);
        $route->middleware(...$this->middleware);
        $this->routes[] = $route;
        return $route;
    }

    public function get(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['GET'], $path, $handler);
    }
    public function post(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['POST'], $path, $handler);
    }
    public function put(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['PUT'], $path, $handler);
    }
    public function patch(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['PATCH'], $path, $handler);
    }
    public function delete(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['DELETE'], $path, $handler);
    }
    public function head(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['HEAD'], $path, $handler);
    }
    public function options(string $path, mixed $handler): RouteDefinition
    {
        return $this->add(['OPTIONS'], $path, $handler);
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function group(string $prefix, callable $configure, array $middleware = [], string $namePrefix = ''): void
    {
        $this->assertMutable();
        $pattern = new RoutePattern($prefix === '' ? '/' : $prefix);
        if (!$pattern->isStatic()) {
            throw new InvalidArgumentException('Group prefixes must be static paths.');
        }
        $previous = [$this->prefix, $this->namePrefix, $this->middleware];
        $this->prefix .= rtrim($prefix, '/');
        $this->namePrefix .= $namePrefix;
        $this->middleware = [...$this->middleware, ...$middleware];
        try {
            $configure($this);
        } finally {
            [$this->prefix, $this->namePrefix, $this->middleware] = $previous;
        }
    }

    /** @return list<RouteDefinition> Static routes first; declaration order within each category. */
    public function all(): array
    {
        $this->freeze();
        $routes = $this->routes;
        usort($routes, static fn(RouteDefinition $a, RouteDefinition $b) => $b->pattern()->isStatic() <=> $a->pattern()->isStatic());
        return $routes;
    }

    public function named(string $name): RouteDefinition
    {
        foreach ($this->all() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }
        throw new InvalidArgumentException("Unknown route name: {$name}");
    }

    public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }
        $names = [];
        $signatures = [];
        foreach ($this->routes as $route) {
            $name = $route->getName();
            if ($name !== null && isset($names[$name])) {
                throw new InvalidArgumentException("Duplicate route name: {$name}");
            }
            if ($name !== null) {
                $names[$name] = true;
            }
            $pattern = $route->pattern();
            foreach ($route->getDefaults() as $key => $value) {
                $pattern->encode($key, $value);
            }
            foreach ($route->methods as $method) {
                $signature = $method . ' ' . $pattern->signature();
                if (isset($signatures[$signature])) {
                    throw new InvalidArgumentException("Duplicate route pattern: {$signature}");
                }
                $signatures[$signature] = true;
            }
        }
        foreach ($this->routes as $route) {
            $route->freeze();
        }
        $this->frozen = true;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Routes cannot be added after the collection is frozen.');
        }
    }
}
