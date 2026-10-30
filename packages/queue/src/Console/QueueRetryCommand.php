<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Crystoline\Xpsr\Queue\Failed\FailedJob;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\Messenger\UniqueJobs;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Queues failed jobs again on the transport they failed on (or queue.default when it is gone),
 * and removes them from the failed jobs table. A #[Unique] job stays there while an identical job
 * is still queued or running.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class QueueRetryCommand extends Command
{
    public function __construct(
        private readonly FailedJobStore $failed,
        private readonly QueueInterface $queue,
        private readonly Transports $transports,
        private readonly UniqueJobs $unique,
    ) {
        parent::__construct('queue:retry');
    }

    protected function configure(): void
    {
        $this->setDescription('Queue failed jobs again.')
            ->addArgument('ids', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'IDs from queue:failed.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Retry every failed job.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $ids = $input->getArgument('ids');
        $ids = is_array($ids) ? array_map(strval(...), $ids) : [];
        if ($ids === [] && !$input->getOption('all')) {
            $errors->error('Name the failed jobs to retry, or pass --all.');

            return self::INVALID;
        }
        $jobs = [];
        if ($input->getOption('all')) {
            $jobs = $this->failed->all();
        }
        foreach ($ids as $id) {
            $job = ctype_digit($id) ? $this->failed->find((int) $id) : null;
            if ($job === null) {
                $errors->error("There is no failed job {$id}.");

                return self::FAILURE;
            }
            $jobs[] = $job;
        }
        $status = self::SUCCESS;
        foreach ($jobs as $job) {
            $status = $this->retry($job, $output, $errors) ? $status : self::FAILURE;
        }

        return $status;
    }

    private function retry(FailedJob $failed, OutputInterface $output, SymfonyStyle $errors): bool
    {
        if ($failed->job === null) {
            $errors->error("Failed job {$failed->id} cannot be read; its class {$failed->jobClass} may have changed. Forget it with php xpsr queue:forget {$failed->id}.");

            return false;
        }
        $transport = $this->transports->has($failed->transport) ? $failed->transport : null;
        $this->queue->dispatch($failed->job, queue: $transport);
        if ($this->unique->dropped($failed->job)) {
            $errors->warning("Failed job {$failed->id} ({$failed->jobClass}) was not queued again: it is #[Unique], and an identical job is still queued or running. It stays in the failed jobs.");

            return false;
        }
        $this->failed->forget($failed->id);
        $output->writeln(OutputFormatter::escape("Queued failed job {$failed->id} ({$failed->jobClass}) again."));

        return true;
    }
}
