<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use InvalidArgumentException;

/**
 * Names the middleware of a configured limit, for routes and app.middleware:
 *
 *   $routes->post('/login', LoginController::class)->middleware(RateLimit::named('login'));
 *
 * Each limit in rate_limit.limits is a container service, "rate_limit.<name>".
 */
final class RateLimit
{
    public const NAME = '/\A[a-z][a-z0-9_-]{0,63}\z/';

    public static function named(string $name): string
    {
        if (!preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException("Rate limit names use lowercase letters, digits, \"_\", and \"-\": \"{$name}\" is not one.");
        }

        return 'rate_limit.' . $name;
    }
}
