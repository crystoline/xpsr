<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Testing;

use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\JobRunner;
use Crystoline\Xpsr\Queue\Messenger\UniqueJob;
use Crystoline\Xpsr\Queue\QueueInterface;

/**
 * A queue for tests: it keeps dispatched jobs instead of sending them.
 *
 *     $this->swap(QueueInterface::class, $queue = new FakeQueue());
 *     $this->postJson('/users', [...]);
 *     self::assertCount(1, $queue->dispatched(SendWelcomeEmail::class));
 *     $queue->run($this->application()->get(JobRunner::class));   // run them now, if the test needs their effects
 *
 * A #[Unique] job is dropped, and listed by dropped(), while an identical job waits here or runs
 * in run(). Its "for" is not counted: the fake keeps the lock until the job runs.
 */
final class FakeQueue implements QueueInterface
{
    /** @var list<array{job: JobInterface, delay: string|int|null, queue: string|null}> */
    private array $dispatched = [];

    /** @var list<JobInterface> */
    private array $dropped = [];

    /** @var array<string, true> the locks of #[Unique] jobs run() is running */
    private array $running = [];

    public function dispatch(JobInterface $job, string|int|null $delay = null, ?string $queue = null): void
    {
        $unique = UniqueJob::of($job);
        if ($unique !== null && $this->held($unique->key)) {
            $this->dropped[] = $job;

            return;
        }
        $this->dispatched[] = ['job' => $job, 'delay' => $delay, 'queue' => $queue];
    }

    /**
     * The dispatched jobs, all or of one class, in order.
     *
     * @template T of JobInterface
     *
     * @param class-string<T>|null $class
     * @return ($class is null ? list<JobInterface> : list<T>)
     */
    public function dispatched(?string $class = null): array
    {
        return self::only(array_column($this->dispatched, 'job'), $class);
    }

    /**
     * The #[Unique] jobs dispatch() dropped because an identical job was waiting or running, all
     * or of one class, in order.
     *
     * @template T of JobInterface
     *
     * @param class-string<T>|null $class
     * @return ($class is null ? list<JobInterface> : list<T>)
     */
    public function dropped(?string $class = null): array
    {
        return self::only($this->dropped, $class);
    }

    /** @return list<array{job: JobInterface, delay: string|int|null, queue: string|null}> each job with its delay and transport */
    public function all(): array
    {
        return $this->dispatched;
    }

    /** Run the dispatched jobs, oldest first, and forget them; jobs they dispatch run too. */
    public function run(JobRunner $runner): void
    {
        while ($this->dispatched !== []) {
            $job = array_shift($this->dispatched)['job'];
            $unique = UniqueJob::of($job);
            // until: 'processing' releases the lock as the job starts; "done" keeps it while it runs.
            $key = $unique !== null && !$unique->untilProcessing ? $unique->key : null;
            if ($key !== null) {
                $this->running[$key] = true;
            }
            try {
                $runner->run($job);
            } finally {
                if ($key !== null) {
                    unset($this->running[$key]);
                }
            }
        }
    }

    private function held(string $key): bool
    {
        if (isset($this->running[$key])) {
            return true;
        }
        foreach ($this->dispatched as $waiting) {
            if (UniqueJob::of($waiting['job'])?->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of JobInterface
     *
     * @param list<JobInterface> $jobs
     * @param class-string<T>|null $class
     * @return ($class is null ? list<JobInterface> : list<T>)
     */
    private static function only(array $jobs, ?string $class): array
    {
        return $class === null ? $jobs : array_values(array_filter($jobs, static fn(JobInterface $job): bool => $job instanceof $class));
    }
}
