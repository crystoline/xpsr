<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog\Http;

use Crystoline\Xpsr\Log\Monolog\RequestId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives each request an ID: the incoming X-Request-Id when it is up to 128 letters, digits,
 * ".", "_", or "-" (as set by a load balancer or the calling service), otherwise a new random
 * one. The ID is logged with every line during the request, available to later code as the
 * "request_id" request attribute, and sent back in the X-Request-Id response header.
 *
 * List it first in app.middleware, so it covers everything after it. Responses rendered from
 * exceptions outside the middleware pipeline do not get the header; their log lines still carry
 * the ID.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestId $requestId) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine(self::HEADER);
        $id = RequestId::from($incoming === '' ? null : $incoming);
        $this->requestId->set($id);

        return $handler->handle($request->withAttribute('request_id', $id))->withHeader(self::HEADER, $id);
    }
}
