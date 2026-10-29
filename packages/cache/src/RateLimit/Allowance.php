<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use Symfony\Component\RateLimiter\RateLimit as Counted;

/** What is left of a key's allowance in a named limit, after one attempt was counted. */
final readonly class Allowance
{
    /**
     * @param bool $accepted whether the attempt was within the limit
     * @param int $limit attempts allowed per interval
     * @param int $remaining attempts left now
     * @param int $retryAfter seconds until another attempt is accepted; 0 while some remain
     */
    public function __construct(
        public bool $accepted,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
    ) {}

    /** @internal */
    public static function of(Counted $counted): self
    {
        $remaining = $counted->getRemainingTokens();
        // Rounded up, so a client that waits this long is never refused again.
        $retryAfter = $remaining > 0 ? 0 : max(1, (int) ceil((float) $counted->getRetryAfter()->format('U.u') - microtime(true)));

        return new self($counted->isAccepted(), $counted->getLimit(), $remaining, $retryAfter);
    }

    /**
     * The headers the middleware sends: X-RateLimit-Limit and X-RateLimit-Remaining.
     *
     * @return array{X-RateLimit-Limit: string, X-RateLimit-Remaining: string}
     */
    public function headers(): array
    {
        return ['X-RateLimit-Limit' => (string) $this->limit, 'X-RateLimit-Remaining' => (string) $this->remaining];
    }
}
