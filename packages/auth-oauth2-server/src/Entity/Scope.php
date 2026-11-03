<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * A scope on a token, as the server sees it.
 *
 * @internal
 */
final class Scope implements ScopeEntityInterface
{
    public function __construct(private readonly string $identifier) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function jsonSerialize(): string
    {
        return $this->identifier;
    }
}
