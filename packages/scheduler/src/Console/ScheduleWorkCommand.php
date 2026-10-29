<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Console;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Runs php xpsr schedule:run at the start of each minute, for development and for containers
 * without cron. Each run is a new process, so it sees code changes. SIGTERM and SIGINT stop it
 * after the current run.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ScheduleWorkCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping = false;

    public function __construct(private readonly ClockInterface $clock, private readonly string $console)
    {
        parent::__construct('schedule:work');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run due scheduled tasks every minute, without cron.')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $limit = $input->getOption('time-limit');
        if ($limit !== null && (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1)) {
            $errors->error('--time-limit must be a number of seconds above 0.');

            return self::INVALID;
        }
        if (!is_file($this->console)) {
            $errors->error("schedule:work runs php xpsr schedule:run each minute, and {$this->console} does not exist.");

            return self::FAILURE;
        }
        $deadline = $limit === null ? null : $this->clock->now()->getTimestamp() + (int) $limit;
        $output->writeln('Running scheduled tasks at the start of each minute. Stop with Ctrl+C.');
        $last = null;
        while (!$this->stopping) {
            $now = $this->clock->now();
            if ($deadline !== null && $now->getTimestamp() >= $deadline) {
                break;
            }
            $minute = $now->format('Y-m-d H:i');
            if ($minute !== $last) {
                $last = $minute;
                $process = new Process([PHP_BINARY, $this->console, 'schedule:run'], dirname($this->console), timeout: null);
                $process->run(static function (string $type, string $buffer) use ($output): void {
                    $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
                });
            }
            // Short sleeps, so a signal stops the loop quickly.
            $this->clock->sleep(1);
        }

        return self::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGTERM') ? [SIGTERM, SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Let the current run finish, then stop.
        $this->stopping = true;

        return false;
    }
}
