<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

use InvalidArgumentException;

/**
 * The "scheduler" configuration (config/scheduler.php, optional), validated:
 *
 *   files     the files that define tasks, relative to the project (default: routes/schedule.php,
 *             which may be missing)
 *   timezone  the time zone schedules are read in, unless a task sets its own (default:
 *             app.timezone)
 *   lock      where withoutOverlapping() keeps its locks: "flock" (the default, files in
 *             var/locks, for one server) or a Symfony Lock store DSN shared by several servers,
 *             such as redis://localhost:6379 or a PDO DSN
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SchedulerOptions
{
    public const DEFAULT_FILE = 'routes/schedule.php';

    /** @param list<string> $files absolute paths */
    private function __construct(
        public readonly array $files,
        public readonly string $timezone,
        public readonly string $lock,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $rootDir, string $appTimezone = 'UTC'): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['files', 'timezone', 'lock'], true)) {
                throw new InvalidArgumentException("Unknown option scheduler.{$key}. Known options: files, timezone, lock.");
            }
        }
        $configured = array_key_exists('files', $config);
        $files = $config['files'] ?? [self::DEFAULT_FILE];
        if (!is_array($files) || !array_is_list($files)) {
            throw new InvalidArgumentException('scheduler.files must be a list of PHP files, such as [\'routes/schedule.php\'].');
        }
        $paths = [];
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                throw new InvalidArgumentException('scheduler.files must be a list of PHP files, such as [\'routes/schedule.php\'].');
            }
            $path = str_starts_with($file, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $file) ? $file : $rootDir . '/' . $file;
            if (!is_file($path)) {
                if ($configured) {
                    throw new InvalidArgumentException("scheduler.files lists {$file}, which does not exist.");
                }
                continue;
            }
            $paths[] = $path;
        }
        $timezone = $config['timezone'] ?? $appTimezone;
        if (!is_string($timezone)) {
            throw new InvalidArgumentException('scheduler.timezone must be a time zone identifier such as UTC or Africa/Lagos.');
        }
        $lock = $config['lock'] ?? 'flock';
        if (!is_string($lock) || $lock === '') {
            throw new InvalidArgumentException('scheduler.lock must be "flock" or a Symfony Lock store DSN such as redis://localhost:6379.');
        }

        return new self($paths, Task::validTimezone($timezone, 'scheduler.timezone'), $lock);
    }
}
