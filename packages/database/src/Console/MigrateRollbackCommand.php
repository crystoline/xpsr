<?php

declare(strict_types=1);

namespace Crystoline\Database\Console;

use Crystoline\Configuration;
use Crystoline\Database\MigratorFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MigrateRollbackCommand extends MigrationCommand
{
    public function __construct(MigratorFactory $migrators, Configuration $config)
    {
        parent::__construct($migrators, $config, 'migrate:rollback');
    }

    protected function configure(): void
    {
        $this->setDescription('Roll back the last batch of migrations.')
            ->addOption('steps', null, InputOption::VALUE_REQUIRED, 'Roll back this many migrations instead of the last batch.')
            ->addConnectionOption()
            ->addForceOption();
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $steps = $input->getOption('steps');
        if ($steps !== null && (!is_string($steps) || !preg_match('/\A[1-9][0-9]*\z/', $steps))) {
            $io->getErrorStyle()->error('--steps must be a whole number of at least 1.');

            return self::INVALID;
        }
        $migrator = $this->migrators->create($this->connectionName($input));
        if (array_filter($migrator->status(), static fn(array $row): bool => $row['status'] !== 'pending') === []) {
            $output->writeln('Nothing to roll back.');

            return self::SUCCESS;
        }
        if (!$this->confirmedInProduction($input, $io, 'Roll back migrations?')) {
            return self::FAILURE;
        }
        $done = $migrator->rollback($steps === null ? null : (int) $steps, function (string $name) use ($output): void {
            $this->started($output, 'Rolling back: ', 'Rolled back:  ', $name);
        });
        $this->finished($output, 'Rolled back:  ');
        $output->writeln(sprintf('Rolled back %d migration(s).', count($done)));

        return self::SUCCESS;
    }
}
