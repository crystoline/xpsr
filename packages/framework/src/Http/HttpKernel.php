<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http;

use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
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
        if ($request->getMethod() === 'HEAD') {
            $response = $response->withBody($this->streams->createStream());
        }

        return $this->matchProtocolVersion($request, $response);
    }

    /**
     * Never answer with a newer HTTP version than the request. Apache with mod_php takes the
     * version from the status line: an HTTP/1.1 answer to an HTTP/1.0 request keeps the connection
     * open until the keep-alive timeout, and clients that read until it closes wait that long.
     */
    private function matchProtocolVersion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $requested = $request->getProtocolVersion();
        // Apache reports subrequests as "INCLUDED", which is not a version.
        if (preg_match('/^\d+(\.\d+)?$/D', $requested) !== 1 || !version_compare($requested, $response->getProtocolVersion(), '<')) {
            return $response;
        }

        return $response->withProtocolVersion($requested);
    }
}
