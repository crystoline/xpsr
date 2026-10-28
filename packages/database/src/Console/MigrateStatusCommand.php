<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\MigratorFactory;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MigrateStatusCommand extends MigrationCommand
{
    public function __construct(MigratorFactory $migrators, Configuration $config)
    {
        parent::__construct($migrators, $config, 'migrate:status');
    }

    protected function configure(): void
    {
        $this->setDescription('Show which migrations have run.')
            ->addConnectionOption()
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $status = $this->migrators->create($this->connectionName($input))->status();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if ($status === []) {
            $output->writeln(OutputFormatter::escape('No migrations in ' . $this->migrators->displayPath() . '/.'));

            return self::SUCCESS;
        }
        $labels = ['ran' => 'Ran', 'pending' => 'Pending', 'missing' => 'Ran, file missing'];
        (new Table($output))
            ->setHeaders(['Migration', 'Status', 'Batch'])
            ->setRows(array_map(static fn(array $row): array => [
                OutputFormatter::escape($row['migration']),
                $labels[$row['status']],
                $row['batch'] === null ? '' : (string) $row['batch'],
            ], $status))
            ->render();
        $counts = array_count_values(array_column($status, 'status'));
        $output->writeln(sprintf('%d ran, %d pending.', ($counts['ran'] ?? 0) + ($counts['missing'] ?? 0), $counts['pending'] ?? 0));

        return self::SUCCESS;
    }
}
