<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log;

use Closure;
use DateTimeInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger that writes through PHP's error_log().
 *
 * Messages go wherever PHP sends errors: the web server's error log, stderr for the CLI and the
 * development server, or the file set by the error_log ini setting. An exception in the
 * "exception" context key is appended with its stack trace.
 */
final class ErrorLogLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $minimum;
    /** @var Closure(string): void */
    private readonly Closure $writer;

    /** @param (Closure(string): void)|null $writer replaces error_log() in tests */
    public function __construct(string $minimumLevel = LogLevel::DEBUG, ?Closure $writer = null)
    {
        $this->minimum = self::severity($minimumLevel);
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };
    }

    /** @param array<array-key, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $severity = self::severity($level);
        if ($severity < $this->minimum) {
            return;
        }
        $line = sprintf('[%s] %s', strtoupper((string) $level), self::interpolate((string) $message, $context));
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $line .= PHP_EOL . $exception;
        }
        ($this->writer)($line);
    }

    private static function severity(mixed $level): int
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Unknown log level: ' . (is_scalar($level) ? (string) $level : get_debug_type($level)));
        }

        return self::LEVELS[$level];
    }

    /** @param array<array-key, mixed> $context */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            } elseif (is_bool($value) || $value === null) {
                $replacements['{' . $key . '}'] = var_export($value, true);
            } elseif ($value instanceof DateTimeInterface) {
                $replacements['{' . $key . '}'] = $value->format(DateTimeInterface::RFC3339);
            }
        }

        return strtr($message, $replacements);
    }
}
