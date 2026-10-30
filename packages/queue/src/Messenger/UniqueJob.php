<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Messenger;

use Crystoline\Xpsr\Queue\Attribute\Unique;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\QueueOptions;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;

/**
 * A job's #[Unique] attribute, checked: its lock's key, how long the lock lasts, and when it is
 * released.
 *
 * @internal Used by UniqueJobs and FakeQueue.
 */
final class UniqueJob
{
    private function __construct(
        public readonly string $key,
        public readonly float $seconds,
        public readonly bool $untilProcessing,
    ) {}

    /** The job's #[Unique] settings, or null when it has none. */
    public static function of(JobInterface $job): ?self
    {
        $attribute = (new ReflectionClass($job))->getAttributes(Unique::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        $unique = $attribute->newInstance();
        $class = $job::class;
        $milliseconds = QueueOptions::milliseconds($unique->for, "#[Unique] for on {$class}");
        if ($milliseconds === 0) {
            throw new InvalidArgumentException("#[Unique] for on {$class} must be at least one second.");
        }
        if (!in_array($unique->until, ['done', 'processing'], true)) {
            throw new InvalidArgumentException("#[Unique] until on {$class} must be \"done\" or \"processing\".");
        }
        $id = method_exists($job, 'uniqueId') ? $job->uniqueId() : null;
        if ($id !== null && !is_string($id) && !is_int($id)) {
            throw new LogicException("{$class}::uniqueId() must return a string or an integer.");
        }

        return new self('queue-unique:' . $class . ($id === null ? '' : ':' . $id), $milliseconds / 1000, $unique->until === 'processing');
    }
}
