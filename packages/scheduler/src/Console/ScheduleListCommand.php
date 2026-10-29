<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Console;

use Crystoline\Xpsr\Scheduler\Schedule;
use Crystoline\Xpsr\Scheduler\Task;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the scheduled tasks with their cron expression, time zone, and next run.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ScheduleListCommand extends Command
{
    public function __construct(private readonly Schedule $schedule, private readonly ClockInterface $clock)
    {
        parent::__construct('schedule:list');
    }

    protected function configure(): void
    {
        $this->setDescription('List the scheduled tasks.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tasks = $this->schedule->tasks();
        if ($tasks === []) {
            $output->writeln('No tasks are scheduled. Define them in routes/schedule.php, or the files in scheduler.files.');

            return self::SUCCESS;
        }
        $now = $this->clock->now();
        (new Table($output))
            ->setHeaders(['Task', 'Schedule', 'Time zone', 'Next run'])
            ->setRows(array_map(static fn(Task $task): array => [
                $task->description() . ($task->lockSeconds() === null ? '' : ' (without overlapping)'),
                $task->expression(),
                $task->timezoneName(),
                $task->nextRun($now)->format('Y-m-d H:i'),
            ], $tasks))
            ->render();

        return self::SUCCESS;
    }
}
