<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

use Throwable;

/**
 * What happened when a task was due: done, skipped (with the reason), or failed (with the error
 * and, for a command, its output).
 */
final class TaskResult
{
    private function __construct(
        public readonly TaskStatus $status,
        public readonly string $message = '',
        public readonly string $output = '',
        public readonly ?Throwable $exception = null,
    ) {}

    public static function done(string $output = ''): self
    {
        return new self(TaskStatus::Done, output: $output);
    }

    public static function skipped(string $reason): self
    {
        return new self(TaskStatus::Skipped, $reason);
    }

    public static function failed(string $message, string $output = '', ?Throwable $exception = null): self
    {
        return new self(TaskStatus::Failed, $message, $output, $exception);
    }
}
