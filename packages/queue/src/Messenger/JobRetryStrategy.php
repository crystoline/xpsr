<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Throwable;

/**
 * A transport's retry strategy: its own retry settings, except for jobs with #[Retry], which
 * replace the settings they give.
 *
 * @internal Built by Transports.
 */
final class JobRetryStrategy implements RetryStrategyInterface
{
    private readonly MultiplierRetryStrategy $transport;

    /** @var array<class-string, RetryStrategyInterface> */
    private array $jobs = [];

    /** @param int $delay in milliseconds, as $maxDelay */
    public function __construct(
        private readonly int $maxRetries,
        private readonly int $delay,
        private readonly float $multiplier,
        private readonly int $maxDelay,
    ) {
        $this->transport = new MultiplierRetryStrategy($maxRetries, $delay, $multiplier, $maxDelay);
    }

    public function isRetryable(Envelope $message, ?Throwable $throwable = null): bool
    {
        return $this->strategy($message)->isRetryable($message, $throwable);
    }

    public function getWaitingTime(Envelope $message, ?Throwable $throwable = null): int
    {
        return $this->strategy($message)->getWaitingTime($message, $throwable);
    }

    private function strategy(Envelope $envelope): RetryStrategyInterface
    {
        $class = $envelope->getMessage()::class;
        if (!isset($this->jobs[$class])) {
            $retry = JobRetry::of($class);
            $this->jobs[$class] = $retry === null ? $this->transport : new MultiplierRetryStrategy(
                $retry->maxRetries ?? $this->maxRetries,
                $retry->delay ?? $this->delay,
                $retry->multiplier ?? $this->multiplier,
                $retry->maxDelay ?? $this->maxDelay,
            );
        }

        return $this->jobs[$class];
    }
}
