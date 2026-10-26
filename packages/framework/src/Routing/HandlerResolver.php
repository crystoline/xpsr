<?php

declare(strict_types=1);

namespace Crystoline\Routing;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class HandlerResolver
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function handle(mixed $handler, ServerRequestInterface $request): ResponseInterface
    {
        if (is_string($handler)) {
            $handler = $this->container->get($handler);
        } elseif (is_array($handler) && is_string($handler[0])) {
            $handler = [$this->container->get($handler[0]), $handler[1]];
        }
        if ($handler instanceof RequestHandlerInterface) {
            return $handler->handle($request);
        }
        if (!is_callable($handler)) {
            throw new UnexpectedValueException('The resolved route handler is neither callable nor PSR-15.');
        }
        $response = $handler($request);
        if (!$response instanceof ResponseInterface) {
            throw new UnexpectedValueException('Route handlers must return a PSR-7 response.');
        }
        return $response;
    }
}
