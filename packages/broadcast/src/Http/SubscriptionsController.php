<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Http;

use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Broadcast\Hub;
use Crystoline\Xpsr\Broadcast\Subscriptions;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An endpoint that authorizes the signed-in user, for a page that opens its subscription from
 * script rather than a template, and to renew the cookie before it expires:
 *
 *     $routes->get('/broadcast/authorize', [SubscriptionsController::class, 'authorize'])->middleware(Authenticate::optional());
 *
 * It answers {"hub": "...", "selectors": [...]} with the cookie; a guest, or a user with nothing
 * to subscribe to, gets the cookie removed.
 */
final class SubscriptionsController
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Hub $hub,
        private readonly ResponseBuilder $responses,
    ) {}

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $user = Identity::find($request)?->user;
        $response = $this->responses
            ->json(['hub' => $this->hub->url(), 'selectors' => $this->subscriptions->selectors($user)])
            ->withHeader('Cache-Control', 'no-store');

        return $this->subscriptions->authorize($response, $user);
    }
}
