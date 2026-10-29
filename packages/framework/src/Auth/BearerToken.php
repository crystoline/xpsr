<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Psr\Http\Message\ServerRequestInterface;

/** Reads the token of an "Authorization: Bearer <token>" header (RFC 6750), for API guards. */
final class BearerToken
{
    /** The token, or null when the request has no bearer token. */
    public static function from(ServerRequestInterface $request): ?string
    {
        if (!preg_match('/\ABearer +([A-Za-z0-9\-._~+\/]+=*) *\z/i', $request->getHeaderLine('Authorization'), $match)) {
            return null;
        }

        return $match[1];
    }

    /** The WWW-Authenticate challenge: plain when no token was sent, invalid_token when one was. */
    public static function challenge(ServerRequestInterface $request): string
    {
        return $request->hasHeader('Authorization') ? 'Bearer error="invalid_token"' : 'Bearer';
    }
}
