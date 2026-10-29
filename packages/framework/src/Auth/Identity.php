<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who a request was authenticated as: the user, the guard that recognized them, and for API
 * tokens what the token allows (its scopes).
 *
 *     $user = Identity::of($request)->user;          // 401 when nobody is signed in
 *     $identity = Identity::find($request);          // null for guests
 */
final class Identity
{
    /**
     * @param list<string>|null $scopes what a token allows; null for sessions, which allow everything
     * @param string|null $tokenId the token's ID, for API tokens
     */
    public function __construct(
        public readonly UserInterface $user,
        public readonly string $guard,
        private readonly ?array $scopes = null,
        public readonly ?string $tokenId = null,
    ) {}

    /** The identity of a request; throws UnauthenticatedException (401) for a guest. */
    public static function of(ServerRequestInterface $request): self
    {
        return self::find($request) ?? throw new UnauthenticatedException();
    }

    /** The identity of a request that passed through Authenticate, or null. */
    public static function find(ServerRequestInterface $request): ?self
    {
        $identity = $request->getAttribute(self::class);

        return $identity instanceof self ? $identity : null;
    }

    /** Whether the credentials allow a scope: always for sessions; for tokens, when listed or "*" is. */
    public function hasScope(string $scope): bool
    {
        return $this->scopes === null || in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }

    /** @return list<string>|null null for sessions */
    public function scopes(): ?array
    {
        return $this->scopes;
    }
}
