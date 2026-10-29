<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth;

/**
 * A user as a set of values, for providers that read rows rather than objects: the database
 * provider and InMemoryUserProvider.
 */
final class GenericUser implements UserInterface
{
    /** @param array<string, mixed> $attributes the other columns, without the password */
    public function __construct(
        private readonly string|int $id,
        private readonly ?string $passwordHash,
        private readonly array $attributes = [],
    ) {}

    public function authIdentifier(): string|int
    {
        return $this->id;
    }

    public function authPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /** @return array<string, mixed> every value except the password hash */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
