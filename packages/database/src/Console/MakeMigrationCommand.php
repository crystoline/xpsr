<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Closure;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\MigrationException;
use Crystoline\Xpsr\Database\Migration\Migrator;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Database\Schema\SchemaDiff;
use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a timestamped migration file. Invalid input returns 2; a name that is already used,
 * or a directory that cannot be written, returns 1.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeMigrationCommand extends Command
{
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param SchemaProviderInterface|null $schema the expected schema, for --diff; bound by ORM adapters
     * @param (Closure(): int)|null $clock returns the current Unix time; for tests
     */
    public function __construct(
        private readonly MigratorFactory $migrators,
        private readonly ConnectionManager $connections,
        private readonly ?SchemaProviderInterface $schema = null,
        ?Closure $clock = null,
    ) {
        parent::__construct('make:migration');
        $this->clock = $clock ?? time(...);
    }

    /** CreateUsersTable and create-users-table become create_users_table. */
    public static function normalizeName(string $name): string
    {
        $words = (string) preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z0-9])([A-Z])/'], '$1_$2', trim($name));

        return trim((string) preg_replace('/[\s_-]+/', '_', strtolower($words)), '_');
    }

    protected function configure(): void
    {
        $this->setDescription('Create a migration file.')
            ->addArgument('name', InputArgument::REQUIRED, 'What the migration does, such as create_users_table.')
            ->addOption('create', null, InputOption::VALUE_REQUIRED, 'Start from a migration that creates this table.')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Start from a migration that changes this existing table.')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'Write the SQL that brings the database in line with the expected schema, such as ORM entity mapping.');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $name = $input->getArgument('name');
        if ($name === null || $name === '') {
            $input->setArgument('name', (new SymfonyStyle($input, $output))->ask('Migration name, such as create_users_table'));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $argument = $input->getArgument('name');
        $name = self::normalizeName(is_string($argument) ? $argument : '');
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/', $name) || strlen($name) > 150) {
            $errors->error('The migration name must start with a letter and contain only letters, digits, and underscores, such as create_users_table.');

            return self::INVALID;
        }
        $create = $input->getOption('create');
        $table = $input->getOption('table');
        if ($create !== null && $table !== null) {
            $errors->error('Use --create or --table, not both.');

            return self::INVALID;
        }
        $tableName = $create ?? $table;
        if ($tableName !== null && (!is_string($tableName) || !preg_match(self::TABLE, $tableName))) {
            $errors->error(sprintf('--%s must be a plain table name: letters, digits, and underscores.', $create !== null ? 'create' : 'table'));

            return self::INVALID;
        }
        $diff = (bool) $input->getOption('diff');
        if ($diff && $tableName !== null) {
            $errors->error('Use --diff on its own, without --create or --table.');

            return self::INVALID;
        }

        $directory = $this->migrators->directory();
        foreach (glob($directory . '/*.php') ?: [] as $existing) {
            if (str_ends_with(basename($existing, '.php'), '_' . $name) && preg_match(Migrator::FILE_PATTERN, basename($existing, '.php'))) {
                $errors->error(sprintf('A migration named %s already exists: %s. Choose another name.', $name, basename($existing)));

                return self::FAILURE;
            }
        }
        $replacements = [];
        if ($diff) {
            try {
                $replacements = $this->diff($errors);
            } catch (MigrationException|DbalException|InvalidArgumentException $exception) {
                $errors->error($exception->getMessage());

                return self::FAILURE;
            }
            if ($replacements === null) {
                return self::FAILURE;
            }
            if ($replacements === []) {
                $output->writeln('The database already matches the schema; no migration written.');

                return self::SUCCESS;
            }
        }
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $errors->error("Could not create the migrations directory {$this->migrators->displayPath()}/.");

            return self::FAILURE;
        }

        $file = gmdate('YmdHis', ($this->clock)()) . "_{$name}.php";
        $stub = match (true) {
            $diff => 'migration.diff',
            $create !== null => 'migration.create',
            $table !== null => 'migration.table',
            default => 'migration',
        };
        $contents = strtr(
            (string) file_get_contents(dirname(__DIR__) . "/Resources/{$stub}.stub"),
            ['{{ table }}' => var_export($tableName, true), ...$replacements],
        );
        // Exclusive creation: an existing file is never replaced.
        $handle = @fopen($directory . '/' . $file, 'x');
        if ($handle === false || fwrite($handle, $contents) !== strlen($contents) || !fclose($handle)) {
            $errors->error("Could not write {$this->migrators->displayPath()}/{$file}.");

            return self::FAILURE;
        }
        $output->writeln(OutputFormatter::escape("Created {$this->migrators->displayPath()}/{$file}"));
        $output->writeln($diff ? 'Next: review the SQL, then run: php xpsr migrate' : 'Next: fill in up() and down(), then run: php xpsr migrate');

        return self::SUCCESS;
    }

    /**
     * The stub values for a --diff migration: [] when there is no difference, null after an error
     * was reported.
     *
     * @return array<string, string>|null
     */
    private function diff(SymfonyStyle $errors): ?array
    {
        if ($this->schema === null) {
            $errors->error('--diff needs a schema to compare with, provided by an ORM package such as crystoline/xpsr-orm-doctrine.');

            return null;
        }
        $connection = $this->schema->connection();
        $pending = $this->migrators->create($connection)->pending();
        if ($pending !== []) {
            $errors->error(sprintf('%d migration(s) have not run yet, so the database is not up to date. Run php xpsr migrate first.', count($pending)));

            return null;
        }
        $db = $this->connections->connection($connection);
        $difference = SchemaDiff::between($db, $this->schema->schema());
        if ($difference->isEmpty()) {
            return [];
        }
        $statements = static fn(array $sql): string => implode("\n", array_map(
            static fn(string $statement): string => '        $db->executeStatement(' . var_export($statement, true) . ');',
            $sql,
        ));

        return [
            '{{ platform }}' => (new ReflectionClass($db->getDatabasePlatform()))->getShortName(),
            '{{ up }}' => $statements($difference->up),
            '{{ down }}' => $difference->down === [] ? '        // Nothing to undo.' : $statements($difference->down),
        ];
    }
}
