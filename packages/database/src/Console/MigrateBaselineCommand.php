<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Migration\Migration;
use Crystoline\Xpsr\Database\Migration\MigrationException;
use Crystoline\Xpsr\Database\Migration\Migrator;
use Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Database\Schema\SchemaCode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the baseline, a migration that creates every table the database has, and records it
 * as run there without running it. On a database that already has the tables of a baseline
 * written elsewhere, it only records it.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MigrateBaselineCommand extends MigrationCommand
{
    /** Named to come before every other migration. */
    public const NAME = '00000000000000_baseline';

    public function __construct(
        MigratorFactoryInterface $migrators,
        Configuration $config,
        private readonly ConnectionManager $connections,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($migrators, $config, 'migrate:baseline');
    }

    protected function configure(): void
    {
        $this->setDescription('Write a migration with every table the database has, and record it as run there.')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A table to leave out of the baseline; repeat for several.')
            ->addConnectionOption()
            ->addForceOption();
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $errors = $io->getErrorStyle();
        if (!$this->migrators instanceof MigratorFactory) {
            $errors->error('migrate:baseline works with XPSR\'s migration runner, and database.migrations.runner names another.');

            return self::FAILURE;
        }
        $connection = $this->connectionName($input);
        $migrator = $this->migrators->create($connection);
        $db = $this->connections->connection($connection);
        $ran = array_column(array_filter($migrator->status(), static fn(array $migration): bool => $migration['status'] !== 'pending'), 'migration');
        if (in_array(self::NAME, $ran, true)) {
            $errors->error('The baseline is already recorded as run on this database.');

            return self::FAILURE;
        }
        $shown = $this->migrators->displayPath() . '/' . self::NAME . '.php';
        if (is_file($migrator->directory() . '/' . self::NAME . '.php')) {
            return $this->recordExisting($input, $output, $io, $migrator, $db, $shown);
        }
        if ($ran !== []) {
            $errors->error(sprintf(
                '%d migration(s) have already run on this database. A baseline must come before every other migration: it would hold their tables too, so a new database would get them twice.',
                count($ran),
            ));

            return self::FAILURE;
        }

        $schemaManager = $db->createSchemaManager();
        $live = $schemaManager->introspectSchema();
        $exclude = array_map(strtolower(...), array_filter((array) $input->getOption('exclude'), is_string(...)));
        $left = [];
        $tables = [];
        foreach ($live->getTables() as $table) {
            $name = self::tableName($table);
            if (strtolower($name) === strtolower($this->migrators->table())) {
                continue;
            }
            if (in_array(strtolower($name), $exclude, true)) {
                $left[] = "{$name} (--exclude)";
            } elseif (self::isLaravelMigrations($table)) {
                $left[] = "{$name} (Laravel's record of migrations)";
            } else {
                $tables[] = $table;
            }
        }
        if ($tables === []) {
            $errors->error('The database has no tables to make a baseline of.');

            return self::FAILURE;
        }
        if (!$this->confirmedInProduction($input, $io, "Write {$shown} and record it as run on this database?")) {
            return self::FAILURE;
        }

        $platform = $db->getDatabasePlatform();
        $directory = $migrator->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new MigrationException("Could not create {$this->migrators->displayPath()}.");
        }
        if (@file_put_contents($directory . '/' . self::NAME . '.php', $this->code($tables, $db)) === false) {
            throw new MigrationException("Could not write {$shown}.");
        }
        $output->writeln(OutputFormatter::escape(sprintf('Wrote %s with the %d tables of this %s database.', $shown, count($tables), self::platformName($platform))));
        if ($left !== []) {
            $output->writeln(OutputFormatter::escape('Left out: ' . implode(', ', $left) . '.'));
        }
        $this->notCovered($output, $schemaManager->introspectViews(), $live->getSequences(), $tables);
        $this->check($io, $migrator, $db, $tables, 'A database it creates would need these statements to match this one:');
        $migrator->record(self::NAME);
        $output->writeln('Recorded it as run on this database, without running it.');
        $output->writeln(OutputFormatter::escape('Next: review and commit it. On a new database, php xpsr migrate creates the tables; on another that has them, such as staging, php xpsr migrate:baseline records it.'));

        return self::SUCCESS;
    }

    /** Records a baseline written on another database, once this one has its tables. */
    private function recordExisting(InputInterface $input, OutputInterface $output, SymfonyStyle $io, Migrator $migrator, Connection $db, string $shown): int
    {
        $expected = self::schemaOf($migrator->migration(self::NAME));
        $live = $db->createSchemaManager()->introspectSchema();
        $tables = [];
        $missing = [];
        foreach ($expected->getTables() as $table) {
            $name = self::tableName($table);
            if ($live->hasTable($name)) {
                $tables[] = $live->getTable($name);
            } else {
                $missing[] = $name;
            }
        }
        if ($tables === []) {
            $io->getErrorStyle()->error("This database has none of the tables in {$shown}. Run php xpsr migrate to create them.");

            return self::FAILURE;
        }
        if ($missing !== []) {
            $io->getErrorStyle()->error(sprintf('This database does not have every table in %s. Missing: %s.', $shown, implode(', ', $missing)));

            return self::FAILURE;
        }
        if (!$this->confirmedInProduction($input, $io, "Record {$shown} as run on this database?")) {
            return self::FAILURE;
        }
        $this->check($io, $migrator, $db, $tables, 'This database differs from it; these statements would make it match the baseline:', reverse: true);
        $migrator->record(self::NAME);
        $output->writeln(OutputFormatter::escape("Recorded {$shown} as run on this database, without running it."));

        return self::SUCCESS;
    }

    /**
     * Compares the tables the baseline builds with the database's. By default the statements
     * shown turn the baseline's tables into the database's; reversed, the other way.
     *
     * @param list<Table> $tables the database's tables
     */
    private function check(SymfonyStyle $io, Migrator $migrator, Connection $db, array $tables, string $message, bool $reverse = false): void
    {
        $built = new Schema(self::schemaOf($migrator->migration(self::NAME))->getTables());
        $actual = new Schema($tables);
        $comparator = $db->createSchemaManager()->createComparator();
        $diff = $reverse ? $comparator->compareSchemas($actual, $built) : $comparator->compareSchemas($built, $actual);
        $statements = $db->getDatabasePlatform()->getAlterSchemaSQL($diff);
        if ($statements === []) {
            $io->writeln($reverse ? 'Its tables match this database\'s.' : 'It builds the same tables as this database has.');

            return;
        }
        $io->warning($message);
        $io->listing(array_map(OutputFormatter::escape(...), $statements));
    }

    /**
     * @param list<View> $views
     * @param list<Sequence> $sequences
     * @param list<Table> $tables
     */
    private function notCovered(OutputInterface $output, array $views, array $sequences, array $tables): void
    {
        if ($views !== []) {
            $names = array_map(static fn(View $view): string => $view->getObjectName()->getUnqualifiedName()->getValue(), $views);
            $output->writeln(OutputFormatter::escape('Not in the baseline: the views ' . implode(', ', $names) . '. Write a migration that creates them.'));
        }
        // Sequences that serve an auto-increment column come back with it.
        $owned = [];
        foreach ($tables as $table) {
            foreach ($table->getColumns() as $column) {
                if ($column->getAutoincrement()) {
                    $owned[] = strtolower(self::tableName($table) . '_' . $column->getObjectName()->getIdentifier()->getValue() . '_seq');
                }
            }
        }
        $other = array_values(array_filter(
            array_map(static fn(Sequence $sequence): string => $sequence->getObjectName()->getUnqualifiedName()->getValue(), $sequences),
            static fn(string $name): bool => !in_array(strtolower($name), $owned, true),
        ));
        if ($other !== []) {
            $output->writeln(OutputFormatter::escape('Not in the baseline: the sequences ' . implode(', ', $other) . '. Write a migration that creates them.'));
        }
    }

    /** @param list<Table> $tables */
    private function code(array $tables, Connection $db): string
    {
        $schemaManager = $db->createSchemaManager();
        $platform = $db->getDatabasePlatform();
        $code = new SchemaCode(
            $tables,
            static fn(Table $table): array => $schemaManager->introspectTableIndexes($table->getObjectName()),
            // SQLite reports its default on every text column.
            $platform instanceof SQLitePlatform ? 'BINARY' : null,
        );
        $classes = array_unique([Migration::class, MigrationException::class, Connection::class, Schema::class, ...$code->classes]);
        sort($classes);
        $stub = (string) file_get_contents(dirname(__DIR__) . '/Resources/migration.baseline.stub');

        return strtr($stub, [
            '{{ uses }}' => implode("\n", array_map(static fn(string $class): string => "use {$class};", $classes)),
            '{{ tables }}' => $code->code,
            '{{ count }}' => (string) count($tables),
            '{{ platform }}' => self::platformName($platform),
            '{{ date }}' => $this->clock->now()->format('Y-m-d'),
        ]);
    }

    private static function schemaOf(Migration $migration): Schema
    {
        $schema = method_exists($migration, 'schema') ? $migration->schema() : null;

        return $schema instanceof Schema ? $schema : throw new MigrationException('The baseline migration has no schema() method that returns its tables. It was not written by this version of migrate:baseline.');
    }

    private static function tableName(Table $table): string
    {
        return $table->getObjectName()->getUnqualifiedName()->getValue();
    }

    /** Laravel's migrations table: id, migration, and batch, and nothing else. */
    private static function isLaravelMigrations(Table $table): bool
    {
        $columns = array_map(static fn($column): string => strtolower($column->getObjectName()->getIdentifier()->getValue()), $table->getColumns());
        sort($columns);

        return $columns === ['batch', 'id', 'migration'];
    }

    private static function platformName(AbstractPlatform $platform): string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform => 'MariaDB',
            $platform instanceof AbstractMySQLPlatform => 'MySQL',
            $platform instanceof PostgreSQLPlatform => 'PostgreSQL',
            $platform instanceof SQLitePlatform => 'SQLite',
            $platform instanceof SQLServerPlatform => 'SQL Server',
            $platform instanceof OraclePlatform => 'Oracle',
            default => (new \ReflectionClass($platform))->getShortName(),
        };
    }
}
