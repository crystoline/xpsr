<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Seeder\SeederServiceRegistry;
use Crystoline\Xpsr\Database\Seeder\SeedReport;
use Crystoline\Xpsr\Database\Seeder\SeedRunner;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\CallbackSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\MembersSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\RolesSeeder;
use Crystoline\Xpsr\Database\Seeder\Tests\Fixtures\Seed\SettingsSeeder;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A temporary project with the console, database, and seeder providers, and the tables the
 * fixture seeders fill. The main connection is the server in XPSR_TEST_PGSQL_URL or
 * XPSR_TEST_MYSQL_URL when set (as in CI's database job), otherwise an SQLite file; the
 * "reports" connection is always SQLite.
 */
final class Project
{
    /** A reference set, and a demo set that needs it and is not for production. */
    public const SEEDS = [
        'sets' => [
            'reference' => [RolesSeeder::class, SettingsSeeder::class],
            'demo' => ['environments' => ['local', 'testing'], 'requires' => ['reference'], 'seeders' => [MembersSeeder::class]],
        ],
    ];

    public readonly string $root;
    public readonly Application $application;

    /** @var list<string> */
    private array $created = [];

    /**
     * @param array<string, mixed> $seeds the "seeds" configuration
     * @param list<class-string> $registries providers to load after the database's, such as an ORM's
     */
    public function __construct(array $seeds = self::SEEDS, string $environment = 'local', array $registries = [])
    {
        Tables::$suffix = bin2hex(random_bytes(3));
        $this->root = sys_get_temp_dir() . '/xpsr-seeder-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/app');
        $this->application = new Application($this->root);
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $config = $this->application->getConfig();
        $config->set('app.env', $environment);
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, ...$registries, SeederServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => [
                'main' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite'],
                'reports' => ['driver' => 'pdo_sqlite', 'path' => 'var/reports.sqlite'],
            ],
        ]);
        $config->set('seeds', $seeds);
    }

    public static function onSqlite(): bool
    {
        return (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL')) === '';
    }

    public function boot(): self
    {
        $this->application->boot();
        foreach ([null, 'reports'] as $connection) {
            if ($this->db($connection)->getDatabasePlatform() instanceof SQLitePlatform) {
                // The files are thrown away with the project, so nothing waits for the disk.
                $this->db($connection)->executeStatement('PRAGMA synchronous = OFF');
            }
        }
        $this->create($this->db(), Table::editor()
            ->setUnquotedName(Tables::name('roles'))
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('slug')->setTypeName(Types::STRING)->setLength(50)->create(),
                Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(100)->create(),
                Column::editor()->setUnquotedName('level')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create(),
                Column::editor()->setUnquotedName('active')->setTypeName(Types::BOOLEAN)->setDefaultValue(true)->create(),
                Column::editor()->setUnquotedName('meta')->setTypeName(Types::JSON)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('fee')->setTypeName(Types::DECIMAL)->setPrecision(10)->setScale(2)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('starts_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create());
        // "key" and "group" are reserved words in SQL.
        $this->create($this->db(), Table::editor()
            ->setUnquotedName(Tables::name('settings'))
            ->setColumns(
                Column::editor()->setQuotedName('key')->setTypeName(Types::STRING)->setLength(100)->create(),
                Column::editor()->setUnquotedName('value')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
                Column::editor()->setQuotedName('group')->setTypeName(Types::STRING)->setLength(50)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setQuotedColumnNames('key')->create())
            ->create());
        $this->create($this->db(), Table::editor()
            ->setUnquotedName(Tables::name('role_user'))
            ->setColumns(
                Column::editor()->setUnquotedName('role_id')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('user_id')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('note')->setTypeName(Types::STRING)->setLength(100)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('role_id', 'user_id')->create())
            ->create());
        $this->db('reports')->executeStatement('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        return $this;
    }

    public function db(?string $name = null): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection($name);
    }

    public function runner(): SeedRunner
    {
        return $this->application->get(SeedRunner::class);
    }

    /**
     * Runs $work as a seeder of its own.
     *
     * @param callable(\Crystoline\Xpsr\Database\Seeder\Seed): mixed $work
     */
    public function seed(callable $work, bool $dryRun = false): SeedReport
    {
        CallbackSeeder::$run = $work(...);

        return $this->runner()->runSeeders([CallbackSeeder::class], $dryRun);
    }

    /**
     * A fixture table's rows, ordered by a column.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, string $orderBy, ?string $connection = null): array
    {
        $db = $this->db($connection);

        return $db->fetchAllAssociative('SELECT * FROM ' . ($connection === null ? Tables::name($table) : $table) . ' ORDER BY ' . $db->quoteSingleIdentifier($orderBy));
    }

    /**
     * One column of a fixture table, ordered by it.
     *
     * @return list<mixed>
     */
    public function column(string $table, string $column): array
    {
        return array_column($this->rows($table, $column), $column);
    }

    public function remove(): void
    {
        CallbackSeeder::$run = null;
        $schema = $this->db()->createSchemaManager();
        foreach ($this->created as $table) {
            $schema->dropTable($table);
        }
        $this->db()->close();
        $this->db('reports')->close();
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** Creates a table on the main connection, to drop it again in remove(). */
    public function create(Connection $db, Table $table): void
    {
        $db->createSchemaManager()->createTable($table);
        $this->created[] = $table->getObjectName()->toString();
    }
}
