<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/** A browser's push subscription: where to push, and the keys to encrypt for it. */
final class PushSubscription
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $publicKey,
        public readonly string $authToken,
        public readonly string $contentEncoding = 'aes128gcm',
    ) {}
}
