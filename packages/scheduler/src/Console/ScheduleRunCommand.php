<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Console;

use Crystoline\Xpsr\Scheduler\Schedule;
use Crystoline\Xpsr\Scheduler\TaskRunner;
use Crystoline\Xpsr\Scheduler\TaskStatus;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the tasks due this minute, one after another. Run it every minute from cron:
 *
 *     * * * * * cd /var/www/app && php xpsr schedule:run > /dev/null
 *
 * Failures go to stderr (so cron mails them) and to the application log, and the other due
 * tasks still run.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ScheduleRunCommand extends Command
{
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
        $due = $this->schedule->due($this->clock->now());
        if ($due === []) {
            $output->writeln('No tasks are due.');

            return self::SUCCESS;
        }
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $failures = 0;
        foreach ($due as $task) {
            $started = hrtime(true);
            $result = $this->runner->run($task, $output->getVerbosity());
            $name = OutputFormatter::escape($task->description());
            if ($result->status === TaskStatus::Done) {
                $output->writeln(sprintf('Ran %s in %.2fs', $name, (hrtime(true) - $started) / 1e9));
                if ($result->output !== '' && $output->isVerbose()) {
                    $output->writeln(self::indent($result->output), OutputInterface::OUTPUT_RAW);
                }
            } elseif ($result->status === TaskStatus::Skipped) {
                $output->writeln("Skipped {$name}: {$result->message}");
            } else {
                ++$failures;
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
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private static function indent(string $text): string
    {
        return '    ' . str_replace("\n", "\n    ", $text);
    }
}
