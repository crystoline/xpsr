<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Testing;

use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\JobRunner;
use Crystoline\Xpsr\Queue\QueueInterface;

/**
 * A queue for tests: it keeps dispatched jobs instead of sending them.
 *
 *     $this->swap(QueueInterface::class, $queue = new FakeQueue());
 *     $this->postJson('/users', [...]);
 *     self::assertCount(1, $queue->dispatched(SendWelcomeEmail::class));
 *     $queue->run($this->application()->get(JobRunner::class));   // run them now, if the test needs their effects
 */
final class FakeQueue implements QueueInterface
{
    /** @var list<array{job: JobInterface, delay: string|int|null, queue: string|null}> */
    private array $dispatched = [];

    public function dispatch(JobInterface $job, string|int|null $delay = null, ?string $queue = null): void
    {
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
        $jobs = array_column($this->dispatched, 'job');

        return $class === null ? $jobs : array_values(array_filter($jobs, static fn(JobInterface $job): bool => $job instanceof $class));
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
            $runner->run(array_shift($this->dispatched)['job']);
        }
    }
}
