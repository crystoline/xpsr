<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Inbound;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses a request whose signature does not verify with 401, and marks a verified one with its
 * verifier's name in the "webhook" request attribute. Build it through the container service
 * VerifyWebhook::named($name).
 */
final class VerifyWebhookMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $name, private readonly InboundSignatures $signatures) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->signatures->verify($this->name, $request);

        return $handler->handle($request->withAttribute('webhook', $this->name));
    }
}
