<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Http;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The token endpoint, for a route such as POST /oauth/token: answers a request of any enabled
 * grant with a JSON token response (access_token, token_type, expires_in, and refresh_token when
 * the grant gives one), or with an OAuth2 error. Clients send a form body with grant_type,
 * client_id and client_secret (or HTTP basic authentication), and the grant's own parameters.
 * Route it outside the session and CSRF middleware: clients are not browsers.
 */
final class TokenController
{
    public function __construct(private readonly AuthorizationServer $server, private readonly ResponseFactoryInterface $responses) {}

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse();
        try {
            return $this->server->respondToAccessTokenRequest($request, $response);
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse($response);
        }
    }
}
