<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

/** A token just created: give plainText to the client once; it cannot be recovered later. */
final class NewAccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $plainText,
        public readonly AccessToken $token,
    ) {}
}
