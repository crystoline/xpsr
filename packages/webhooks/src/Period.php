<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Seconds from a number or a period such as "5 minutes".
 *
 * @internal
 */
final class Period
{
    public static function seconds(mixed $period, string $what): int
    {
        $seconds = match (true) {
            is_int($period) => $period,
            is_float($period) => (int) $period,
            is_string($period) => self::parse($period),
            default => null,
        };
        if ($seconds === null || $seconds <= 0) {
            throw new InvalidArgumentException("{$what} must be more than zero seconds, or a period such as \"5 minutes\".");
        }

        return $seconds;
    }

    private static function parse(string $period): ?int
    {
        if (is_numeric($period)) {
            return (int) $period;
        }
        try {
            $interval = DateInterval::createFromDateString($period);
        } catch (\Exception) {
            return null;
        }
        $seconds = new DateTimeImmutable('@0')->add($interval)->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }
}
