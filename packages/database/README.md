# crystoline/xpsr-database

Database connections and migrations for XPSR applications, backed by [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html). DBAL gives one API for SQLite, MySQL, MariaDB, PostgreSQL, and others: queries with bound parameters, a query builder, transactions, and portable schema changes. This package configures the connections and adds `migrate`, `migrate:rollback`, `migrate:status`, and `make:migration`. It is not an ORM.

```sh
composer require crystoline/xpsr-database
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Database\DatabaseServiceRegistry::class,
```

The skeleton's `config/database.php` already has SQLite, MySQL, and PostgreSQL connections. The default is SQLite in `var/database.sqlite`, which needs no server; the `pdo_sqlite` extension must be loaded. To use MySQL, set in `.env`:

```sh
DB_CONNECTION=mysql
DATABASE_URL=mysql://app:secret@127.0.0.1:3306/app
```

## Queries

Inject `Doctrine\DBAL\Connection`, the default connection. It connects on the first query.

```php
use Doctrine\DBAL\Connection;

public function __construct(private readonly Connection $db) {}

$user = $this->db->fetchAssociative('SELECT id, name FROM users WHERE id = ?', [$id]);  // array|false
$names = $this->db->fetchFirstColumn('SELECT name FROM users ORDER BY name');
$this->db->insert('users', ['name' => 'Ada', 'email' => 'ada@example.com']);
$this->db->update('users', ['name' => 'Ada Lovelace'], ['id' => $id]);

$this->db->transactional(function (Connection $db) use ($order): void {
    $db->insert('orders', $order);
    $db->executeStatement('UPDATE stock SET quantity = quantity - ? WHERE sku = ?', [1, $order['sku']]);
});
```

Always pass values as parameters, never by building SQL strings. See the [DBAL documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/data-retrieval-and-manipulation.html) for the query builder, types, and the rest of the API.

## Configuration

`config/database.php`:

```php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => ['driver' => 'pdo_sqlite', 'path' => env('DB_DATABASE', 'var/database.sqlite')],
        'mysql' => [
            'url' => env('DATABASE_URL'),
            'driver' => 'pdo_mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            // ...
        ],
        'reporting' => ['url' => env('REPORTING_DATABASE_URL')],
    ],
    'migrations' => ['path' => 'migrations', 'table' => 'migrations'],
    'log_queries' => false,
];
```

| Setting | Meaning |
|---|---|
| `default` | The connection used by `Doctrine\DBAL\Connection` and by commands without `--connection`. |
| `connections.<name>` | [DBAL connection parameters](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/configuration.html): `driver`, `host`, `port`, `dbname`, `user`, `password`, `charset`, `path` (SQLite), `memory` (SQLite), `driverOptions`, and others. |
| `connections.<name>.url` | A URL such as `mysql://user:secret@host:3306/app`, `postgresql://user:secret@host/app`, or `sqlite:///var/app.sqlite`. When set and not empty, its values replace the separate settings. Encode special characters in the password (`@` as `%40`). |
| `migrations.path` | Directory of migration files, relative to the project root. |
| `migrations.table` | Table that records which migrations have run. |
| `log_queries` | `true` logs every query at debug level through the application's PSR-3 logger. Use it in development only: it logs parameters too. |

A relative SQLite `path` is resolved against the project root, and its directory is created if needed. Invalid settings fail when the connection is first used, with the setting's name.

Other connections come from `Crystoline\Database\ConnectionManager`:

```php
$reports = $connections->connection('reporting');
```

## Migrations

```sh
php xpsr make:migration create_users_table --create=users
```

This writes `migrations/20261028120000_create_users_table.php`: a UTC timestamp (year, month, day, hour, minute, second), an underscore, and the name. Migrations run in file-name order, which is the order they were created. A migration file returns a `Crystoline\Database\Migration\Migration`, usually an anonymous class:

```php
use Crystoline\Database\Migration\Migration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

return new class extends Migration {
    public function up(Connection $db): void
    {
        $db->createSchemaManager()->createTable(
            Table::editor()
                ->setUnquotedName('users')
                ->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                    Column::editor()->setUnquotedName('email')->setTypeName(Types::STRING)->setLength(255)->create(),
                )
                ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->create(),
        );
    }

    public function down(Connection $db): void
    {
        $db->createSchemaManager()->dropTable('users');
    }
};
```

DBAL's schema API produces the right SQL for each database. Plain SQL works too: `$db->executeStatement('CREATE INDEX ...')`. To change an existing table, `make:migration add_name_to_users --table=users` starts from the `alterTable()` helper:

```php
$this->alterTable($db, 'users', static function (TableEditor $table): void {
    $table->addColumn(Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(100)->setNotNull(false)->create());
});
```

| Command | What it does |
|---|---|
| `php xpsr migrate` | Runs every pending migration, in file-name order, as one batch. |
| `php xpsr migrate:rollback` | Rolls back the last batch, newest first. `--steps=N` rolls back the last N migrations instead. |
| `php xpsr migrate:status` | Lists each migration as ran, pending, or "ran, file missing", with its batch. `--json` for scripts. |
| `php xpsr make:migration <name>` | Writes a timestamped file. `--create=table` starts from a create-table migration and `--table=table` from an alter-table one. `CreateUsersTable` and `create-users-table` both become `create_users_table`. A name already used by another migration is refused. |

All accept `--connection=<name>`. Each connection keeps its own migrations table.

**Production.** With `APP_ENV=production`, `migrate` and `migrate:rollback` ask for confirmation in a terminal and refuse without `--force` otherwise. Deployment scripts run `php xpsr migrate --force`.

**Failures.** A migration that throws stops the run; later migrations do not run, and the command exits with 1 naming the migration. Migrations that completed before it stay applied and recorded. On PostgreSQL and SQLite, a migration runs in a transaction with its bookkeeping, so a failed migration leaves nothing behind. MySQL and MariaDB commit each schema change immediately: a migration that fails part-way stays partly applied and needs repairing by hand, so keep each migration small there. Set `protected bool $transactional = false;` in a migration whose statements cannot run in a transaction, such as PostgreSQL's `CREATE INDEX CONCURRENTLY`.

**Rolling back.** `down()` is optional. Without it, rolling back that migration fails with a message, and nothing after it in the rollback runs. A migration whose file was deleted after it ran shows as "ran, file missing" and cannot be rolled back.

## Testing

Use an in-memory SQLite database for tests, in `config/testing/database.php`:

```php
return [
    'default' => 'testing',
    'connections' => ['testing' => ['driver' => 'pdo_sqlite', 'memory' => true]],
];
```

Each test gets a new application and therefore an empty database. Run the migrations in `setUp()`:

```php
protected function setUp(): void
{
    [$status] = $this->console('migrate');
    self::assertSame(0, $status);
}
```

SQLite differs from MySQL and PostgreSQL in places, such as type checking and some SQL functions. Run the suite against your production database type as well before relying on it.

## Services

| Service | Provides |
|---|---|
| `Doctrine\DBAL\Connection` | The default connection. Shared. |
| `Crystoline\Database\ConnectionManager` | Every configured connection, by name: `connection('reporting')`. Shared. |
| `Crystoline\Database\MigratorFactory` | `create(?string $connection)` returns a `Migrator` for running migrations from code. |

This package's tests run against SQLite, and against MySQL 8.4, MariaDB 11, and PostgreSQL 17 when `XPSR_TEST_MYSQL_URL` or `XPSR_TEST_PGSQL_URL` is set.

Requires PHP 8.4+ and the PDO extension for your database.
