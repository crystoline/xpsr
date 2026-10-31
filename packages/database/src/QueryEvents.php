<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database;

use Crystoline\Xpsr\Database\Event\QueryExecuted;
use DateInterval;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * What happens after each query on the connections of ConnectionManager, whether DBAL or an ORM
 * ran it: with database.log_queries, it is logged at the debug level; over database.slow_queries,
 * it is logged as a warning; and with the event dispatcher, a QueryExecuted event is dispatched
 * for the listeners in config/events.php.
 */
final class QueryEvents
{
    public function __construct(
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $logQueries = false,
        private readonly ?float $slowSeconds = null,
    ) {}

    /** Whether anything is done with queries, so connections can skip timing them. */
    public function active(): bool
    {
        return $this->events !== null || ($this->logger !== null && ($this->logQueries || $this->slowSeconds !== null));
    }

    /**
     * A query ran on $connection.
     *
     * @param array<int|string, mixed> $parameters
     */
    public function executed(string $connection, string $sql, array $parameters, float $seconds): void
    {
        if ($this->logger !== null) {
            $context = ['connection' => $connection, 'sql' => $sql, 'parameters' => $parameters, 'ms' => round($seconds * 1000, 2)];
            if ($this->logQueries) {
                $this->logger->debug('Query on {connection} took {ms} ms: {sql}', $context);
            }
            if ($this->slowSeconds !== null && $seconds >= $this->slowSeconds) {
                $this->logger->warning('Slow query on {connection} took {ms} ms: {sql}', $context);
            }
        }
        $this->events?->dispatch(new QueryExecuted($connection, $sql, $parameters, $seconds));
    }

    /**
     * A duration from configuration, in seconds: a number of seconds, or a period such as
     * "500 milliseconds" or "2 seconds". Null, false, and "" (an unset environment variable)
     * mean none.
     */
    public static function seconds(mixed $value, string $option): ?float
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        $seconds = null;
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $seconds = (float) $value;
        } elseif (is_string($value)) {
            try {
                $interval = DateInterval::createFromDateString($value);
                $seconds = (($interval->d * 24 + $interval->h) * 60 + $interval->i) * 60 + $interval->s + $interval->f;
            } catch (\Exception) {
                $seconds = null;
            }
        }
        if ($seconds === null || $seconds <= 0) {
            throw new InvalidArgumentException("{$option} must be a number of seconds above 0, or a period such as \"500 milliseconds\"; null for none.");
        }

        return $seconds;
    }
}
