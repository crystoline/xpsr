<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Routing\Event;

use Crystoline\Xpsr\Routing\RouteDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched through the application's PSR-14 dispatcher once a request has matched a route,
 * before the route's middleware and handler run. The request carries the route parameters as
 * attributes, with the route itself as "_route".
 */
final class RouteMatched
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly RouteDefinition $route,
        public readonly array $parameters,
        public readonly ServerRequestInterface $request,
    ) {}
}
