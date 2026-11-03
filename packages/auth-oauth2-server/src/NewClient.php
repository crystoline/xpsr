<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

/** A client just registered, with its secret: show the secret once, it is stored only as a hash. */
final class NewClient
{
    /** @param string|null $secret null for a public client, which has none */
    public function __construct(
        #[\SensitiveParameter]
        public readonly ?string $secret,
        public readonly Client $client,
    ) {}
}
