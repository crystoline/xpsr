<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\BearerToken;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recognizes API tokens from TokenRepository sent as "Authorization: Bearer <token>"
 * (auth.guards driver "token"). The identity carries the token's scopes and ID.
 */
final class TokenGuard implements GuardInterface
{
    public function __construct(
        private readonly string $name,
        private readonly TokenRepository $tokens,
        private readonly UserProviderInterface $users,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $text = BearerToken::from($request);
        $token = $text === null ? null : $this->tokens->find($text);
        if ($token === null) {
            return null;
        }
        $user = $this->users->findById(ctype_digit($token->userId) && $token->userId[0] !== '0' ? (int) $token->userId : $token->userId);

        return $user === null ? null : new Identity($user, $this->name, $token->scopes, (string) $token->id);
    }

    public function challenge(ServerRequestInterface $request): string
    {
        return BearerToken::challenge($request);
    }
}
