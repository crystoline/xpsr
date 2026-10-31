<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Console;

use Crystoline\Xpsr\Scheduler\Schedule;
use Crystoline\Xpsr\Scheduler\Task;
use Crystoline\Xpsr\Scheduler\TaskRunner;
use Crystoline\Xpsr\Scheduler\TaskStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the tasks due this minute, one after another. Run it every minute from cron:
 *
 *     * * * * * cd /var/www/app && php xpsr schedule:run > /dev/null
 *
 * Tasks with everySeconds() are then repeated until the minute schedule:run started in ends.
 * Failures go to stderr (so cron mails them) and to the application log, and the other due
 * tasks still run. SIGTERM and SIGINT end the repeats after the current task.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ScheduleRunCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping = false;

    public function __construct(
        private readonly Schedule $schedule,
        private readonly TaskRunner $runner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct('schedule:run');
    }

    protected function configure(): void
    {
        $this->setDescription('Run the scheduled tasks that are due.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Every task is checked against the minute schedule:run started in.
        $now = $this->clock->now();
        $started = (float) $now->format('U.u');
        $due = $this->schedule->due($now);
        if ($due === []) {
            $output->writeln('No tasks are due.');

            return self::SUCCESS;
        }
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $failures = 0;
        /** @var array<int, float> when each everySeconds() task next runs, by its index in $due */
        $next = [];
        foreach ($due as $index => $task) {
            $ranAt = $this->now();
            $failures += $this->runTask($task, $output, $errors);
            if ($task->repeatSeconds() !== null) {
                $next[$index] = $ranAt + $task->repeatSeconds();
            }
        }
        $end = floor($started / 60) * 60 + 60;
        while ($next !== [] && !$this->stopping && ($now = $this->now()) < $end) {
            $soonest = $end;
            foreach ($next as $index => $at) {
                if ($at <= $now) {
                    $task = $due[$index];
                    $failures += $this->runTask($task, $output, $errors);
                    // The next run is a period after this one began, so a run that overran is not caught up on.
                    $next[$index] = $at = $now + (int) $task->repeatSeconds();
                    $now = $this->now();
                }
                $soonest = min($soonest, $at);
            }
            if ($soonest > $now) {
                $this->clock->sleep($soonest - $now);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Let the current task finish, then stop repeating.
        $this->stopping = true;

        return false;
    }

    /** Runs one task and reports it; returns 1 when it failed. */
    private function runTask(Task $task, OutputInterface $output, OutputInterface $errors): int
    {
        $started = hrtime(true);
        $result = $this->runner->run($task, $output->getVerbosity());
        $name = OutputFormatter::escape($task->description());
        if ($result->status === TaskStatus::Done) {
            $output->writeln(sprintf('Ran %s in %.2fs', $name, (hrtime(true) - $started) / 1e9));
            if ($result->output !== '' && $output->isVerbose()) {
                $output->writeln(self::indent($result->output), OutputInterface::OUTPUT_RAW);
            }

            return 0;
        }
        if ($result->status === TaskStatus::Skipped) {
            $output->writeln("Skipped {$name}: {$result->message}");

            return 0;
        }
        $errors->writeln("<error>Failed</error> {$name}: " . OutputFormatter::escape($result->message));
        if ($result->output !== '') {
            $errors->writeln(self::indent($result->output), OutputInterface::OUTPUT_RAW);
        }
        $this->logger->error('Scheduled task {task} failed: {message}', array_filter([
            'task' => $task->description(),
            'message' => $result->message,
            'output' => $result->output,
            'exception' => $result->exception,
        ], static fn(mixed $value): bool => $value !== '' && $value !== null));

        return 1;
    }

    /** The clock's time as seconds, with the fraction. */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private static function indent(string $text): string
    {
        return '    ' . str_replace("\n", "\n    ", $text);
    }
}
