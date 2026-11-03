<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Crystoline\Xpsr\Auth\OAuth2\Entity\Scope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * The scopes of oauth.scopes, with the descriptions the consent page shows. A token gets only
 * configured scopes, and only those its client may ask for.
 */
final class Scopes implements ScopeRepositoryInterface
{
    public function __construct(private readonly OAuthOptions $options) {}

    /** @return array<string, string> scope => description */
    public function all(): array
    {
        return $this->options->scopes;
    }

    public function has(string $scope): bool
    {
        return $this->options->hasScope($scope);
    }

    /** The description of a scope, for the consent page; the scope itself when it has none. */
    public function describe(string $scope): string
    {
        return $this->options->scopes[$scope] ?? $scope;
    }

    /** @internal */
    public function getScopeEntityByIdentifier(mixed $identifier): ?ScopeEntityInterface
    {
        $identifier = (string) $identifier;

        return $this->options->hasScope($identifier) ? new Scope($identifier) : null;
    }

    /**
     * @internal The server's last check before a token is issued: a client gets only the scopes it may ask for.
     *
     * @param ScopeEntityInterface[] $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(array $scopes, mixed $grantType, ClientEntityInterface $clientEntity, mixed $userIdentifier = null, mixed $authCodeId = null): array
    {
        if ($clientEntity instanceof Client) {
            foreach ($scopes as $scope) {
                $id = (string) $scope->getIdentifier();
                if (!$clientEntity->allowsScope($id)) {
                    throw OAuthServerException::invalidScope($id);
                }
            }
        }

        return array_values($scopes);
    }
}
