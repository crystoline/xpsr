<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Log\Monolog;

use InvalidArgumentException;
use Monolog\Level;

/**
 * The "logging" configuration (config/logging.php), each setting falling back to an
 * environment variable:
 *
 *   channel  stderr (default), file, daily, or syslog                       LOG_CHANNEL
 *   format   line, or json; json by default when APP_ENV is production      LOG_FORMAT
 *   level    the lowest level written (default debug)                       LOG_LEVEL
 *   path     file and daily: the log file, relative to the project root (default var/log/app.log)
 *   days     daily: how many files to keep (default 14)
 *   name     the Monolog channel name in each line (default app.name)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class LoggingOptions
{
    public const CHANNELS = ['stderr', 'file', 'daily', 'syslog'];

    private function __construct(
        public readonly string $channel,
        public readonly string $format,
        public readonly Level $level,
        public readonly string $path,
        public readonly int $days,
        public readonly string $name,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, mixed> $environment LOG_CHANNEL, LOG_FORMAT, LOG_LEVEL
     */
    public static function from(array $config, array $environment, string $appEnv, string $appName, string $rootDir): self
    {
        $known = ['channel', 'format', 'level', 'path', 'days', 'name'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option logging.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $channel = self::lower($config['channel'] ?? $environment['LOG_CHANNEL'] ?? null) ?? 'stderr';
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException(sprintf('The log channel (logging.channel or LOG_CHANNEL) must be %s, not "%s".', implode(', ', self::CHANNELS), $channel));
        }
        $format = self::lower($config['format'] ?? $environment['LOG_FORMAT'] ?? null) ?? ($appEnv === 'production' ? 'json' : 'line');
        if ($format !== 'line' && $format !== 'json') {
            throw new InvalidArgumentException("The log format (logging.format or LOG_FORMAT) must be line or json, not \"{$format}\".");
        }
        $levelName = self::lower($config['level'] ?? $environment['LOG_LEVEL'] ?? null) ?? 'debug';
        try {
            $level = Level::fromName($levelName);
        } catch (\UnhandledMatchError|\ValueError) {
            throw new InvalidArgumentException("The log level (logging.level or LOG_LEVEL) must be a PSR-3 level such as debug, info, or warning, not \"{$levelName}\".");
        }
        $path = self::text($config['path'] ?? null) ?? 'var/log/app.log';
        $absolute = str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path) === 1;
        $days = $config['days'] ?? 14;
        if (!is_int($days) || $days < 1) {
            throw new InvalidArgumentException('logging.days must be a whole number of days, 1 or more.');
        }
        $name = self::text($config['name'] ?? null) ?? ($appName !== '' ? $appName : 'app');

        return new self($channel, $format, $level, $absolute ? $path : $rootDir . '/' . $path, $days, $name);
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Logging settings must be strings, except logging.days.');
        }

        return trim($value);
    }

    private static function lower(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : strtolower($text);
    }
}
