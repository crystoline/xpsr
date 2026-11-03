<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Http;

use Crystoline\Xpsr\Broadcast\Subscriptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the authorization cookie that hub_url() asked for during the request to the response. List
 * it in app.middleware; the user is the one signed in when the template rendered.
 */
final class SubscriptionsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->subscriptions->begin();
        try {
            $response = $handler->handle($request);
        } finally {
            $cookie = $this->subscriptions->finish();
        }

        return $cookie === null ? $response : $cookie->addTo($response);
    }
}
