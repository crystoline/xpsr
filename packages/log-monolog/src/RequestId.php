<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog;

/**
 * The ID of the request being handled, set by Http\RequestIdMiddleware and added to every log
 * line by RequestIdProcessor. Null outside a request, for example in console commands.
 */
final class RequestId
{
    /** Accepted from X-Request-Id: up to 128 letters, digits, ".", "_", and "-". */
    public const PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    private ?string $current = null;

    public function get(): ?string
    {
        return $this->current;
    }

    public function set(?string $id): void
    {
        $this->current = $id;
    }

    /** The incoming ID when it is safe to log and echo, otherwise a new random one. */
    public static function from(?string $incoming): string
    {
        return $incoming !== null && preg_match(self::PATTERN, $incoming) ? $incoming : bin2hex(random_bytes(16));
    }
}
