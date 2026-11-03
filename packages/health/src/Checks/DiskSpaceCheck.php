<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Checks;

use Closure;
use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;

/** The free space of the disk the project is on: a warning below health.disk.warning percent, failing below health.disk.failing. */
final class DiskSpaceCheck implements CheckInterface
{
    /** @param (Closure(string): array{float|false, float|false})|null $space free and total bytes of a path; the filesystem's by default */
    public function __construct(
        private readonly string $path,
        private readonly float $warning,
        private readonly float $failing,
        private readonly ?Closure $space = null,
    ) {}

    public function name(): string
    {
        return 'disk';
    }

    public function check(): Result
    {
        if (!is_dir($this->path)) {
            return Result::failing(sprintf('"%s" is not a directory', $this->path), ['path' => $this->path]);
        }
        $space = $this->space ?? static fn(string $path): array => [disk_free_space($path), disk_total_space($path)];
        [$free, $total] = $space($this->path);
        if ($free === false || $total === false || $total <= 0) {
            return Result::failing(sprintf('Cannot read the disk space of "%s"', $this->path), ['path' => $this->path]);
        }
        $percent = $free / $total * 100;
        $details = ['path' => $this->path, 'free_bytes' => (int) $free, 'total_bytes' => (int) $total, 'free_percent' => round($percent, 1)];
        $message = sprintf('%.1f%% free (%s of %s)', $percent, self::size($free), self::size($total));
        if ($percent < $this->failing) {
            return Result::failing($message, $details);
        }
        if ($percent < $this->warning) {
            return Result::warning($message, $details);
        }

        return Result::ok($message, $details);
    }

    private static function size(float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return sprintf($unit === 'B' ? '%d %s' : '%.1f %s', $bytes, $unit);
            }
            $bytes /= 1024;
        }

        return '';
    }
}
