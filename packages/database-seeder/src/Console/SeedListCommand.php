<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Console;

use Crystoline\Xpsr\Database\Seeder\SeedRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the seed sets: their seeders, and whether this environment may run them.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SeedListCommand extends Command
{
    public function __construct(private readonly SeedRunner $runner)
    {
        parent::__construct('seed:list');
    }

    protected function configure(): void
    {
        $this->setDescription('List the seed sets, their seeders, and whether this environment may run them.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the sets as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $options = $this->runner->options();
        $environment = $this->runner->environment();
        $sets = [];
        foreach ($options->sets as $set) {
            $sets[] = [
                'set' => $set->name,
                'default' => $set->name === $options->default,
                'environments' => $set->environments,
                'runs_here' => $set->allows($environment),
                'requires' => $set->requires,
                'seeders' => $set->seeders,
            ];
        }
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($sets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($sets === []) {
            $output->writeln('config/seeds.php defines no sets.');

            return self::SUCCESS;
        }
        $rows = [];
        foreach ($sets as $set) {
            $rows[] = [
                $set['set'] . ($set['default'] ? ' (default)' : ''),
                $set['environments'] === null ? 'all' : implode(', ', $set['environments']),
                $set['runs_here'] ? 'yes' : 'no',
                $set['requires'] === [] ? '' : implode(', ', $set['requires']),
                implode("\n", $set['seeders']),
            ];
        }
        $io->table(['Set', 'Environments', "Runs in {$environment}", 'After', 'Seeders'], $rows);

        return self::SUCCESS;
    }
}
