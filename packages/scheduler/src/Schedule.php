<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

use Closure;
use Crystoline\Xpsr\Queue\JobInterface;
use DateTimeInterface;
use ReflectionFunction;

/**
 * The application's scheduled tasks, defined in routes/schedule.php:
 *
 *     return static function (Schedule $schedule): void {
 *         $schedule->command('session:prune')->daily('03:00');
 *         $schedule->job(new PruneUploads())->hourly();
 *         $schedule->call(static function (TaskRepository $tasks): void {
 *             $tasks->archiveFinished();
 *         })->name('archive tasks')->weekly('sunday', '02:00');
 *     };
 *
 * php xpsr schedule:run, from one cron entry each minute, runs the tasks that are due.
 */
final class Schedule
{
    /** @var list<Task> */
    private array $tasks = [];

    public function __construct(private readonly string $timezone = 'UTC') {}

    /** A console command with its arguments, such as "report:send --weekly". */
    public function command(string $command): Task
    {
        return $this->tasks[] = Task::forCommand($command, $this->timezone);
    }

    /**
     * A callable, run in the schedule:run process. Its parameters are services, by type. Without
     * $name, it is described by its file and line.
     */
    public function call(callable $callback, ?string $name = null): Task
    {
        $callback = Closure::fromCallable($callback);
        $task = Task::forCallback($callback, $name ?? self::describe($callback), $this->timezone);

        return $this->tasks[] = $task;
    }

    /**
     * A job queued on its schedule, on $queue or the default transport; a worker runs it. Needs
     * crystoline/xpsr-queue.
     */
    public function job(JobInterface $job, ?string $queue = null): Task
    {
        return $this->tasks[] = Task::forJob($job, $queue, $this->timezone);
    }

    /** @return list<Task> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** @return list<Task> the tasks whose schedule matches the minute of $now */
    public function due(DateTimeInterface $now): array
    {
        return array_values(array_filter($this->tasks, static fn(Task $task): bool => $task->isDue($now)));
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    private static function describe(Closure $callback): string
    {
        $function = new ReflectionFunction($callback);
        if (!str_starts_with($function->getName(), '{closure')) {
            // A named function or method, passed as 'name', [$object, 'method'], or name(...).
            $class = $function->getClosureCalledClass();

            return ($class === null ? '' : $class->getName() . '::') . $function->getName();
        }
        $file = $function->getFileName();

        return 'Closure at ' . ($file === false ? 'unknown file' : basename($file)) . ':' . $function->getStartLine();
    }
}
