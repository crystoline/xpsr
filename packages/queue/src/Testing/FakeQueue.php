<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Testing;

use Closure;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\JobRunner;
use Crystoline\Xpsr\Queue\Messenger\UniqueJob;
use Crystoline\Xpsr\Queue\QueueInterface;
use PHPUnit\Framework\Assert;

/**
 * A queue for tests: it keeps dispatched jobs instead of sending them.
 *
 *     $this->swap(QueueInterface::class, $queue = new FakeQueue());
 *     $this->postJson('/users', [...]);
 *     $queue->assertDispatched(SendWelcomeEmail::class, static fn(SendWelcomeEmail $job): bool => $job->userId === 7);
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

    /**
     * A job of this class was dispatched: at least one, exactly $times, or one for which the
     * closure returns true.
     *
     * @param class-string<JobInterface> $class
     * @param int|Closure(JobInterface): bool|null $times
     */
    public function assertDispatched(string $class, int|Closure|null $times = null): void
    {
        $jobs = $this->dispatched($class);
        if ($times instanceof Closure) {
            Assert::assertTrue(array_any($jobs, static fn(JobInterface $job): bool => (bool) $times($job)), sprintf('No %s job that passes the check was dispatched; %d of that class were.%s', $class, count($jobs), $this->dispatchedSummary()));
        } elseif ($times === null) {
            Assert::assertNotEmpty($jobs, "No {$class} job was dispatched." . $this->dispatchedSummary());
        } else {
            Assert::assertCount($times, $jobs, sprintf('Expected %d %s jobs to be dispatched, not %d.%s', $times, $class, count($jobs), $this->dispatchedSummary()));
        }
    }

    /**
     * No job of this class was dispatched, or none for which the closure returns true.
     *
     * @param class-string<JobInterface> $class
     * @param (Closure(JobInterface): bool)|null $check
     */
    public function assertNotDispatched(string $class, ?Closure $check = null): void
    {
        $jobs = array_filter($this->dispatched($class), static fn(JobInterface $job): bool => $check === null || (bool) $check($job));
        Assert::assertCount(0, $jobs, sprintf('%d %s job(s) were dispatched%s.', count($jobs), $class, $check === null ? '' : ' that pass the check'));
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertSame([], $this->dispatched, 'Jobs were dispatched.' . $this->dispatchedSummary());
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

    private function dispatchedSummary(): string
    {
        $classes = array_map(static fn(array $entry): string => $entry['job']::class, $this->dispatched);

        return $classes === [] ? ' Nothing was dispatched.' : ' Dispatched: ' . implode(', ', $classes) . '.';
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
