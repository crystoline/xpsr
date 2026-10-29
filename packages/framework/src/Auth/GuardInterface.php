<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * One way of recognizing users, configured in auth.guards: "session" (browsers), "jwt"
 * (crystoline/xpsr-auth-jwt), or "token" (crystoline/xpsr-database).
 */
interface GuardInterface
{
    /** The identity the request proves, or null when it carries no valid credentials. */
    public function authenticate(ServerRequestInterface $request): ?Identity;

    /**
     * The WWW-Authenticate header for a 401 answer to this request, such as
     * 'Bearer error="invalid_token"'; null when browsers are sent to the login page instead.
     */
    public function challenge(ServerRequestInterface $request): ?string;
}
