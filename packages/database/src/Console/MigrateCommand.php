<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\MigratorFactory;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MigrateCommand extends MigrationCommand
{
    public function __construct(MigratorFactory $migrators, Configuration $config)
    {
        parent::__construct($migrators, $config, 'migrate');
    }

    protected function configure(): void
    {
        $this->setDescription('Run the pending database migrations.')
            ->addConnectionOption()
            ->addForceOption();
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $migrator = $this->migrators->create($this->connectionName($input));
        if ($migrator->pending() === []) {
            $output->writeln('Nothing to migrate.');

            return self::SUCCESS;
        }
        if (!$this->confirmedInProduction($input, $io, 'Run the migrations?')) {
            return self::FAILURE;
        }
        $ran = $migrator->migrate(function (string $name) use ($output): void {
            $this->started($output, 'Migrating: ', 'Migrated:  ', $name);
        });
        $this->finished($output, 'Migrated:  ');
        $output->writeln(sprintf('Ran %d migration(s).', count($ran)));

        return self::SUCCESS;
    }
}
