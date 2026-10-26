<?php

declare(strict_types=1);

namespace Crystoline\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    private readonly RequestHandlerInterface $pipeline;

    /** @param list<MiddlewareInterface> $middleware First listed runs first. */
    public function __construct(RequestHandlerInterface $handler, array $middleware = [])
    {
        foreach (array_reverse($middleware) as $item) {
            $handler = new class ($item, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        $this->pipeline = $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }
}
