<?php

declare(strict_types=1);

namespace Crystoline\Http;

use Crystoline\Exception\ExceptionHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class HttpKernel implements RequestHandlerInterface
{
    private readonly MiddlewarePipeline $pipeline;

    /** @param list<MiddlewareInterface> $middleware First listed runs first. */
    public function __construct(
        RequestHandlerInterface $handler,
        private readonly ExceptionHandlerInterface $exceptions,
        private readonly StreamFactoryInterface $streams,
        array $middleware = [],
    ) {
        $this->pipeline = new MiddlewarePipeline($handler, $middleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->pipeline->handle($request);
        } catch (Throwable $exception) {
            $response = $this->exceptions->render($request, $exception);
        }
        // Apply after global middleware and error rendering, including short-circuit responses.
        return $request->getMethod() === 'HEAD' ? $response->withBody($this->streams->createStream()) : $response;
    }
}
