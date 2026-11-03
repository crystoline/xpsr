<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use DateTimeImmutable;

/** An access token the server issued, as recorded: for a page that lists the applications a user connected. */
final class IssuedToken
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly ?string $userId,
        public readonly array $scopes,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
