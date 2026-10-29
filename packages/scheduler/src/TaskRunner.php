<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler;

use Closure;
use Crystoline\Xpsr\Console\ConsoleKernel;
use Crystoline\Xpsr\Queue\QueueInterface;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/**
 * Runs one due task: checks its conditions, takes its lock for withoutOverlapping(), then runs
 * the command through the console kernel, calls the callback, or queues the job. A task's
 * failure is returned, not thrown, so the other due tasks still run.
 *
 * @internal Used by schedule:run.
 */
final class TaskRunner
{
    public function __construct(private readonly ContainerInterface $container, private readonly LockFactory $locks) {}

    public function run(Task $task, int $verbosity = OutputInterface::VERBOSITY_NORMAL): TaskResult
    {
        try {
            foreach ($task->conditions() as [$condition, $runsWhen]) {
                if ((bool) $this->call($condition, $task) !== $runsWhen) {
                    return TaskResult::skipped($runsWhen ? 'its when() condition is false' : 'its skip() condition is true');
                }
            }
        } catch (Throwable $exception) {
            return TaskResult::failed($exception->getMessage(), exception: $exception);
        }

        $lock = null;
        if ($task->lockSeconds() !== null) {
            $lock = $this->locks->createLock($task->lockKey(), $task->lockSeconds());
            if (!$lock->acquire()) {
                return TaskResult::skipped('the previous run is still going');
            }
        }
        try {
            return match ($task->kind) {
                'command' => $this->command((string) $task->command, $verbosity),
                'call' => $this->callback($task),
                'job' => $this->job($task),
            };
        } catch (Throwable $exception) {
            return TaskResult::failed($exception->getMessage(), exception: $exception);
        } finally {
            $lock?->release();
        }
    }

    private function command(string $command, int $verbosity): TaskResult
    {
        $input = new StringInput($command);
        $input->setInteractive(false);
        $output = new BufferedOutput($verbosity);
        $code = $this->container->get(ConsoleKernel::class)->run($input, $output);
        $text = rtrim($output->fetch());

        return $code === 0 ? TaskResult::done($text) : TaskResult::failed("exit code {$code}", $text);
    }

    private function callback(Task $task): TaskResult
    {
        $this->call($task->callback ?? throw new LogicException('A call task has no callback.'), $task);

        return TaskResult::done();
    }

    private function job(Task $task): TaskResult
    {
        if (!interface_exists(QueueInterface::class) || !$this->container->has(QueueInterface::class)) {
            throw new LogicException('Schedule::job() needs crystoline/xpsr-queue: run composer require crystoline/xpsr-queue, and add Crystoline\Xpsr\Queue\QueueServiceRegistry to app.registry.');
        }
        $job = $task->job ?? throw new LogicException('A job task has no job.');
        $this->container->get(QueueInterface::class)->dispatch($job, queue: $task->queue);

        return TaskResult::done();
    }

    /** Calls $function with each parameter from the container by its class or interface type. */
    private function call(Closure $function, Task $task): mixed
    {
        $arguments = [];
        foreach ((new ReflectionFunction($function))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
                $arguments[] = $this->container->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $arguments[] = null;
            } else {
                throw new LogicException(sprintf(
                    'The scheduled task "%s" has a callback parameter $%s that needs a class or interface type the container provides, or a default value.',
                    $task->description(),
                    $parameter->getName(),
                ));
            }
        }

        return $function(...$arguments);
    }
}
