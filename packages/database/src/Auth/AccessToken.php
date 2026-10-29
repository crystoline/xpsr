<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use DateTimeImmutable;

/** An API token's stored details. The token itself is stored only as a hash. */
final class AccessToken
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly int $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly array $scopes,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $lastUsedAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly string $guard = 'api',
    ) {}
}
