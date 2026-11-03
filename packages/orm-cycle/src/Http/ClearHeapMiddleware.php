<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Http;

use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Forgets the pending changes and the loaded entities after each request, so a process that
 * serves several requests (a worker server) does not keep entities from one request in the next.
 * With PHP-FPM or Apache every request starts fresh and this middleware is not needed.
 */
final class ClearHeapMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ORMInterface $orm, private readonly EntityManagerInterface $entities) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } finally {
            $this->entities->clean();
            $this->orm->getHeap()->clean();
        }
    }
}
