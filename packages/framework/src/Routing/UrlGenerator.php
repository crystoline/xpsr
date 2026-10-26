<?php

declare(strict_types=1);

namespace Crystoline\Routing;

use Crystoline\Contracts\Routing\UrlGeneratorInterface;

final class UrlGenerator implements UrlGeneratorInterface
{
    public function __construct(private readonly RouteCollection $routes)
    {
        $routes->freeze();
    }

    public function generate(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->routes->named($name);
        $path = $route->pattern()->generate([...$route->getDefaults(), ...$parameters]);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $path . ($queryString === '' ? '' : '?' . $queryString);
    }
}
