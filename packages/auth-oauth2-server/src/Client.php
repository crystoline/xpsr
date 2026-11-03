<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Crystoline\Xpsr\Auth\UserInterface;
use DateTimeImmutable;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * An application that asks the server for tokens. A confidential client (a server) proves itself
 * with its secret; a public client (a mobile or browser application) has no secret and must use
 * PKCE. A token from the client credentials grant authenticates as the client itself, so
 * Identity::of($request)->user is then the Client; it implements UserInterface for that.
 */
final class Client implements ClientEntityInterface, UserInterface
{
    /**
     * @param list<string> $redirectUris where the authorization code grant may send browsers back to
     * @param list<string> $grants the grants the client may use
     * @param list<string>|null $scopes the scopes it may ask for; null for every configured scope
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $redirectUris,
        public readonly array $grants,
        public readonly ?array $scopes,
        public readonly bool $confidential,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $revokedAt = null,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /** Whether the client may ask for a scope. */
    public function allowsScope(string $scope): bool
    {
        return $this->scopes === null || in_array($scope, $this->scopes, true);
    }

    /** Whether the client may use a grant, such as client_credentials. */
    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grants, true);
    }

    public function getIdentifier(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getRedirectUri(): array
    {
        return $this->redirectUris;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    /** The client's ID: what a client credentials token authenticates as. */
    public function authIdentifier(): string
    {
        return $this->id;
    }

    public function authPasswordHash(): ?string
    {
        return null;
    }
}
