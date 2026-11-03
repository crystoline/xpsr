<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Entity;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * The signed-in user who decides on an authorization request, as the server sees them.
 *
 * @internal
 */
final class ResourceOwner implements UserEntityInterface
{
    public function __construct(private readonly string $identifier) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
