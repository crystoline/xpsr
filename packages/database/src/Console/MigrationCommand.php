<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Migration\MigrationException;
use Crystoline\Xpsr\Database\MigratorFactory;
use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared behavior for the commands that read or change the database. Configuration, migration,
 * and database errors are printed to stderr and return 1.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
abstract class MigrationCommand extends Command
{
    private ?string $current = null;
    private int $completed = 0;

    public function __construct(
        protected readonly MigratorFactory $migrators,
        protected readonly Configuration $config,
        string $name,
    ) {
        parent::__construct($name);
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->resetProgress();
        try {
            return $this->handle($input, $output, $io);
        } catch (MigrationException|DbalException|InvalidArgumentException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());
            $completed = $this->completedCount();
            if ($completed > 0) {
                $io->getErrorStyle()->writeln(sprintf('The %d migration(s) before it in this run completed.', $completed));
            }

            return self::FAILURE;
        }
    }

    protected function addConnectionOption(): static
    {
        return $this->addOption('connection', null, InputOption::VALUE_REQUIRED, 'The connection to use, from database.connections. Defaults to database.default.');
    }

    protected function addForceOption(): static
    {
        return $this->addOption('force', null, InputOption::VALUE_NONE, 'Run without asking when APP_ENV is production.');
    }

    protected function connectionName(InputInterface $input): ?string
    {
        $name = $input->getOption('connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** In production, changing the database needs --force, or a confirmation in a terminal. */
    protected function confirmedInProduction(InputInterface $input, SymfonyStyle $io, string $question): bool
    {
        if ($this->config->get('app.env', 'production') !== 'production' || $input->getOption('force')) {
            return true;
        }
        if (!$input->isInteractive()) {
            $io->getErrorStyle()->error('The application is in production (APP_ENV=production). Pass --force to change the database.');

            return false;
        }

        return $io->confirm("The application is in production. {$question}", false);
    }

    /** Progress output: the previous migration finished, and the next one starts. */
    protected function started(OutputInterface $output, string $starting, string $done, string $name): void
    {
        $this->finished($output, $done);
        $this->current = $name;
        $output->writeln(OutputFormatter::escape($starting . $name));
    }

    private function resetProgress(): void
    {
        $this->current = null;
        $this->completed = 0;
    }

    private function completedCount(): int
    {
        return $this->completed;
    }

    protected function finished(OutputInterface $output, string $done): void
    {
        if ($this->current !== null) {
            $output->writeln(OutputFormatter::escape($done . $this->current));
            $this->current = null;
            ++$this->completed;
        }
    }
}
