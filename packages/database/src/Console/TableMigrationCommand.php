<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Closure;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\Migration\Migrator;
use Crystoline\Xpsr\Database\MigratorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the migration for a table a package needs, named by a configuration option, from a stub
 * in Resources/. Refuses a second one.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
abstract class TableMigrationCommand extends Command
{
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock returns the current Unix time; for tests */
    public function __construct(
        string $name,
        private readonly MigratorFactory $migrators,
        private readonly Configuration $config,
        ?Closure $clock = null,
    ) {
        parent::__construct($name);
        $this->clock = $clock ?? time(...);
    }

    /** The configuration option that names the table, such as session.database.table. */
    abstract protected function option(): string;

    abstract protected function defaultTable(): string;

    /** The stub's name in Resources/, without .stub. */
    abstract protected function stub(): string;

    /** What the table is for, as in "A migration for the <what> already exists". */
    abstract protected function what(): string;

    abstract protected function nextStep(): string;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $table = $this->config->get($this->option(), $this->defaultTable());
        if (!is_string($table) || !preg_match(self::TABLE, $table)) {
            $errors->error("{$this->option()} must be a plain table name: letters, digits, and underscores.");

            return self::INVALID;
        }
        $name = 'create_' . strtolower($table) . '_table';
        $directory = $this->migrators->directory();
        foreach (glob($directory . '/*.php') ?: [] as $existing) {
            $base = basename($existing, '.php');
            if (str_ends_with($base, '_' . $name) && preg_match(Migrator::FILE_PATTERN, $base)) {
                $errors->error("A migration for the {$this->what()} already exists: {$base}.php.");

                return self::FAILURE;
            }
        }
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $errors->error("Could not create the migrations directory {$this->migrators->displayPath()}/.");

            return self::FAILURE;
        }
        $file = gmdate('YmdHis', ($this->clock)()) . "_{$name}.php";
        $contents = strtr((string) file_get_contents(dirname(__DIR__) . "/Resources/{$this->stub()}.stub"), [
            '{{ table }}' => var_export($table, true),
            // For index names, inside quotes in the stub; table names are letters, digits, and underscores.
            '{{ prefix }}' => substr($table, 0, 40),
        ]);
        $handle = @fopen($directory . '/' . $file, 'x');
        if ($handle === false || fwrite($handle, $contents) !== strlen($contents) || !fclose($handle)) {
            $errors->error("Could not write {$this->migrators->displayPath()}/{$file}.");

            return self::FAILURE;
        }
        $output->writeln(OutputFormatter::escape("Created {$this->migrators->displayPath()}/{$file}"));
        $output->writeln($this->nextStep());

        return self::SUCCESS;
    }
}
