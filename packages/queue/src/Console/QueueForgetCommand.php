<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes failed jobs from the failed jobs table without running them.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class QueueForgetCommand extends Command
{
    public function __construct(private readonly FailedJobStore $failed)
    {
        parent::__construct('queue:forget');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove failed jobs.')
            ->addArgument('ids', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'IDs from queue:failed.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Remove every failed job.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        if ($input->getOption('all')) {
            $output->writeln(sprintf('Forgot %d failed job(s).', $this->failed->flush()));

            return self::SUCCESS;
        }
        $ids = $input->getArgument('ids');
        if (!is_array($ids) || $ids === []) {
            $errors->error('Name the failed jobs to forget, or pass --all.');

            return self::INVALID;
        }
        foreach (array_map(strval(...), $ids) as $id) {
            if (!ctype_digit($id) || !$this->failed->forget((int) $id)) {
                $errors->error("There is no failed job {$id}.");

                return self::FAILURE;
            }
            $output->writeln("Forgot failed job {$id}.");
        }

        return self::SUCCESS;
    }
}
