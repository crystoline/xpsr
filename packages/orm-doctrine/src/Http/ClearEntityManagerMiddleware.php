<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Http;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Clears the entity manager after each request, so a process that serves several requests
 * (a worker server) does not keep entities from one request in the next. With PHP-FPM or Apache
 * every request starts fresh and this middleware is not needed.
 */
final class ClearEntityManagerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } finally {
            $this->entityManager->clear();
        }
    }
}
