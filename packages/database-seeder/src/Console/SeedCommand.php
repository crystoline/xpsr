<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Console;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Seeder\SeedException;
use Crystoline\Xpsr\Database\Seeder\SeedReport;
use Crystoline\Xpsr\Database\Seeder\SeedRunner;
use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads seed data: the default set, or the sets named. Errors are printed to stderr and return 1.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SeedCommand extends Command
{
    public function __construct(
        private readonly SeedRunner $runner,
        private readonly Configuration $config,
    ) {
        parent::__construct('seed');
    }

    protected function configure(): void
    {
        $this->setDescription('Load seed data: the default set, or the sets named.')
            ->addArgument('set', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The sets to load, from seeds.sets. Defaults to seeds.default.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, and change nothing.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Run one seeder of the sets alone, by its class name or the last part of it.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run without asking when APP_ENV is production.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sets = array_values(array_filter((array) $input->getArgument('set'), 'is_string'));
        $dryRun = (bool) $input->getOption('dry-run');
        $only = $input->getOption('only');
        try {
            // Unknown sets, and sets that are not for this environment, are refused before anything is asked.
            $this->runner->resolve($sets);
            if (!$dryRun && !$this->confirmedInProduction($input, $io)) {
                return self::FAILURE;
            }
            $report = $this->runner->run($sets, $dryRun, is_string($only) && $only !== '' ? $only : null);
        } catch (SeedException|InvalidArgumentException|DbalException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->write($output, $report, $dryRun || $output->isVerbose());

        return self::SUCCESS;
    }

    /** In production, changing the database needs --force, or a confirmation in a terminal, as migrate does. */
    private function confirmedInProduction(InputInterface $input, SymfonyStyle $io): bool
    {
        if ($this->config->get('app.env', 'production') !== 'production' || $input->getOption('force')) {
            return true;
        }
        if (!$input->isInteractive()) {
            $io->getErrorStyle()->error('The application is in production (APP_ENV=production). Pass --force to load the seed data, or --dry-run to see what it would change.');

            return false;
        }

        return $io->confirm('The application is in production. Load the seed data?', false);
    }

    private function write(OutputInterface $output, SeedReport $report, bool $changes): void
    {
        $set = false;
        foreach ($report->seeders() as $seeder) {
            if ($seeder->set !== $set) {
                $set = $seeder->set;
                $output->writeln($set === null ? 'Seeding' : OutputFormatter::escape("Seeding {$set}"));
            }
            $output->writeln('  ' . OutputFormatter::escape($seeder->seeder));
            foreach ($seeder->tables() as $table) {
                $output->writeln('    ' . OutputFormatter::escape("{$table->table}: {$table->summary()}"));
                if (!$changes) {
                    continue;
                }
                foreach ($table->changes as $line) {
                    $output->writeln('      ' . OutputFormatter::escape($line));
                }
                if ($table->omitted > 0) {
                    $output->writeln("      and {$table->omitted} more");
                }
            }
        }
        $counts = sprintf('%d inserted, %d updated, %d deleted', $report->inserted(), $report->updated(), $report->deleted());
        if ($report->dryRun) {
            $output->writeln($report->changed() ? "Dry run, nothing was changed. A run would give: {$counts}." : 'Dry run: there is nothing to change.');

            return;
        }
        $output->writeln($report->changed() ? "Seeded: {$counts}." : 'Nothing to change: the data is as the seeders describe it.');
    }
}
