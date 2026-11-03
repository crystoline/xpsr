<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Closure;
use Crystoline\Xpsr\Auth\BearerToken;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recognizes the server's access tokens sent as "Authorization: Bearer <token>" (auth.guards
 * driver "oauth2"). A token counts with a valid signature from the server's key, until it expires
 * or is revoked. The identity is the user who approved the application, looked up in the guard's
 * provider, with the scopes they approved; for a client credentials token it is the Client itself.
 */
final class OAuth2Guard implements GuardInterface
{
    /** @param Closure(): ResourceServer $server the resource server, built when the first token arrives */
    public function __construct(
        private readonly string $name,
        private readonly Closure $server,
        private readonly Clients $clients,
        private readonly UserProviderInterface $users,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        if (BearerToken::from($request) === null) {
            return null;
        }
        try {
            $request = ($this->server)()->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            return null;
        }
        $tokenId = $request->getAttribute('oauth_access_token_id');
        $clientId = $request->getAttribute('oauth_client_id');
        $userId = $request->getAttribute('oauth_user_id');
        $scopes = $request->getAttribute('oauth_scopes');
        if (!is_string($tokenId) || !is_string($clientId) || !is_array($scopes)) {
            return null;
        }
        // A client credentials token names its client as the subject (RFC 9068), or in version 8 of
        // the library has no subject; a user's token names the user.
        if (is_string($userId) && $userId !== '' && $userId !== $clientId) {
            $user = $this->users->findById(ctype_digit($userId) && $userId[0] !== '0' ? (int) $userId : $userId);
        } else {
            $client = $this->clients->find($clientId);
            $user = $client === null || $client->isRevoked() ? null : $client;
        }

        return $user === null ? null : new Identity($user, $this->name, array_values(array_filter($scopes, is_string(...))), $tokenId);
    }

    public function challenge(ServerRequestInterface $request): string
    {
        return BearerToken::challenge($request);
    }
}
