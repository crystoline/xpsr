<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Attribute;

use Attribute;

/**
 * How a job is retried after it fails, in place of its transport's retry settings:
 *
 *     #[Retry(maxRetries: 5, delay: '30 seconds', multiplier: 2, maxDelay: '10 minutes')]
 *     final class IssueRefund implements JobInterface { ... }
 *
 * Each setting left out keeps the transport's value.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Retry
{
    /**
     * @param int|null $maxRetries how many times the job is tried again; 0 for none
     * @param string|int|null $delay seconds, or a period such as "30 seconds", before the first retry
     * @param float|int|null $multiplier how much longer each retry after the first waits: 2 doubles the delay each time
     * @param string|int|null $maxDelay the most a retry waits
     */
    public function __construct(
        public readonly ?int $maxRetries = null,
        public readonly string|int|null $delay = null,
        public readonly float|int|null $multiplier = null,
        public readonly string|int|null $maxDelay = null,
    ) {}
}
