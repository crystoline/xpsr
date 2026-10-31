<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Crystoline\Xpsr\Queue\Attribute\Retry;
use Crystoline\Xpsr\Queue\QueueOptions;
use InvalidArgumentException;
use ReflectionClass;

/**
 * A job class's #[Retry] attribute, checked: delays in milliseconds, and null for each setting
 * that keeps the transport's value.
 *
 * @internal Used by JobRetryStrategy and Queue.
 */
final class JobRetry
{
    private function __construct(
        public readonly ?int $maxRetries,
        public readonly ?int $delay,
        public readonly ?float $multiplier,
        public readonly ?int $maxDelay,
    ) {}

    /**
     * The class's #[Retry] settings, or null when it has none.
     *
     * @param class-string $class
     */
    public static function of(string $class): ?self
    {
        $attribute = (new ReflectionClass($class))->getAttributes(Retry::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        $retry = $attribute->newInstance();
        if ($retry->maxRetries !== null && $retry->maxRetries < 0) {
            throw new InvalidArgumentException("#[Retry] maxRetries on {$class} must be 0 or more.");
        }
        if ($retry->multiplier !== null && $retry->multiplier < 1) {
            throw new InvalidArgumentException("#[Retry] multiplier on {$class} must be 1 or more.");
        }

        return new self(
            $retry->maxRetries,
            $retry->delay === null ? null : QueueOptions::milliseconds($retry->delay, "#[Retry] delay on {$class}"),
            $retry->multiplier === null ? null : (float) $retry->multiplier,
            $retry->maxDelay === null ? null : QueueOptions::milliseconds($retry->maxDelay, "#[Retry] maxDelay on {$class}"),
        );
    }
}
