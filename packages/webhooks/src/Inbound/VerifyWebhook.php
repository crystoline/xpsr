<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Inbound;

use InvalidArgumentException;

/**
 * The middleware service that verifies a provider's webhooks, for a route or group:
 *
 *     $routes->post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
 *         ->middleware(VerifyWebhook::named('stripe'));
 *
 * The name is an entry of webhooks.inbound. A request with a missing or wrong signature gets
 * 401; a verified request carries the name in its "webhook" attribute.
 */
final class VerifyWebhook
{
    public static function named(string $name): string
    {
        if (!preg_match('/\A[a-z0-9_-]+\z/', $name)) {
            throw new InvalidArgumentException("Webhook verifier names use lowercase letters, digits, \"_\", and \"-\": \"{$name}\" is not one.");
        }

        return 'webhooks.inbound.' . $name;
    }
}
