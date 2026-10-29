<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt;

use DateTimeImmutable;

/** A JWT just issued: send $token to the client, which sends it back as "Authorization: Bearer <token>". */
final class IssuedToken
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly string $id,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $guard = 'api',
    ) {}
}
