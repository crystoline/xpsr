<?php

declare(strict_types=1);

namespace Crystoline\Routing;

final readonly class RouteMatch
{
    private function __construct(
        public ?RouteDefinition $route,
        public array $parameters,
        public array $allowedMethods,
    ) {}

    public static function found(RouteDefinition $route, array $parameters = []): self
    {
        return new self($route, $parameters, []);
    }

    /** An empty Allow list means no matching path; otherwise the method is unsupported. */
    public static function missing(array $allowedMethods = []): self
    {
        return new self(null, [], $allowedMethods);
    }
}
