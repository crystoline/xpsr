<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Http;

use Crystoline\Xpsr\Routing\Event\RouteMatched;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Tracer;

/**
 * Names the request span after the matched route, "GET /payments/{id}", and sets http.route.
 *
 * @internal Listens for RouteMatched when the requests instrumentation is on.
 */
final class RouteListener
{
    public function __construct(private readonly Tracer $tracer) {}

    public function __invoke(RouteMatched $event): void
    {
        $span = $this->tracer->current();
        if ($span === null) {
            return;
        }
        $span->setName($event->request->getMethod() . ' ' . $event->route->path);
        $span->setAttribute(Attributes::HTTP_ROUTE, $event->route->path);
        if ($event->route->getName() !== null) {
            $span->setAttribute(Attributes::XPSR_ROUTE_NAME, $event->route->getName());
        }
    }
}
