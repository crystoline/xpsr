<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists jobs that ran out of retries.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class QueueFailedCommand extends Command
{
    public function __construct(private readonly FailedJobStore $failed)
    {
        parent::__construct('queue:failed');
    }

    protected function configure(): void
    {
        $this->setDescription('List failed jobs.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = array_map(static fn($job): array => [
            'id' => $job->id,
            'job' => $job->jobClass,
            'transport' => $job->transport,
            'failed_at' => $job->failedAt->format(DATE_ATOM),
            'error' => strtok($job->error, "\n") ?: '',
        ], $this->failed->all());
        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        (new Table($output))
            ->setHeaders(['ID', 'Job', 'Transport', 'Failed at', 'Error'])
            ->setRows(array_map(static fn(array $row): array => array_map(static fn($value): string => OutputFormatter::escape((string) $value), $row), $rows))
            ->render();
        $output->writeln(count($rows) . ' failed job(s). Retry with php xpsr queue:retry <id>, or forget with php xpsr queue:forget <id>.');

        return self::SUCCESS;
    }
}
