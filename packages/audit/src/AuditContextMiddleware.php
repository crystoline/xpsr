<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use Crystoline\Xpsr\Http\ClientIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Marks the entries recorded while a request is handled with its client IP and user agent. List
 * it in app.middleware, before the authentication middleware or after, as it reads the identity
 * only when an entry is recorded.
 */
final class AuditContextMiddleware implements MiddlewareInterface
{
    /** @param list<string> $trustedProxies app.trusted_proxies */
    public function __construct(private readonly AuditContext $context, private readonly array $trustedProxies = []) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $agent = $request->getHeaderLine('User-Agent');
        $this->context->set('http', ClientIp::of($request, $this->trustedProxies), $agent === '' ? null : mb_substr($agent, 0, 512));
        try {
            return $handler->handle($request);
        } finally {
            $this->context->clear();
        }
    }
}
