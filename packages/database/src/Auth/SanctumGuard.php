<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\BearerToken;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\UserProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Recognizes Laravel Sanctum tokens from SanctumTokenRepository sent as
 * "Authorization: Bearer <token>" (auth.guards driver "sanctum"). The identity carries the
 * token's abilities as its scopes, and the token's ID.
 */
final class SanctumGuard implements GuardInterface
{
    public function __construct(
        private readonly string $name,
        private readonly SanctumTokenRepository $tokens,
        private readonly UserProviderInterface $users,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        // Sanctum's tokens hold "|", which RFC 6750's token syntax leaves out and BearerToken
        // refuses. Laravel reads them, so this guard takes any printable text without spaces.
        $text = preg_match('/\ABearer +([\x21-\x7E]+) *\z/i', $request->getHeaderLine('Authorization'), $match) ? $match[1] : null;
        $token = $text === null ? null : $this->tokens->find($text, $this->name);
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
