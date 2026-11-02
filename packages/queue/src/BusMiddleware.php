<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use Symfony\Component\Messenger\Middleware\MiddlewareInterface;

/**
 * Messenger middleware other providers add to the queue's message bus, as service IDs or
 * instances, in boot(). They run first, in the order added, both when a job is dispatched and
 * when a received job is handled, before the bus sends or handles it:
 *
 *     $this->container->get(BusMiddleware::class)->add(TraceJobs::class);
 *
 * A middleware tells the two apart by the envelope's ReceivedStamp. Add them before the bus is
 * first used; the bus is built with what is there then.
 */
final class BusMiddleware
{
    /** @var list<string|MiddlewareInterface> */
    private array $middleware = [];

    public function add(string|MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /** @return list<string|MiddlewareInterface> */
    public function all(): array
    {
        return $this->middleware;
    }
}
