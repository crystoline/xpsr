<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures;

use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\OAuth2\Client;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The fixture project's API, behind the oauth2 guard. */
final class Controllers
{
    public function __construct(private readonly ResponseBuilder $responses) {}

    /** Who the token authenticates as. */
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $identity = Identity::of($request);
        $user = $identity->user;

        return $this->responses->json([
            'subject' => $user instanceof Client ? 'client' : 'user',
            'id' => $user->authIdentifier(),
            'name' => $user instanceof Client ? $user->name : ($user instanceof GenericUser ? $user->get('name') : null),
            'guard' => $identity->guard,
            'scopes' => $identity->scopes(),
            'token' => $identity->tokenId,
        ]);
    }

    /** Needs the orders:read scope. */
    public function orders(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->json(['orders' => [42]]);
    }
}
