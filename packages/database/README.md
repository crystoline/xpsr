# crystoline/xpsr-database

Database connections and migrations for XPSR applications, backed by [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html). DBAL gives one API for SQLite, MySQL, MariaDB, PostgreSQL, and others: queries with bound parameters, a query builder, transactions, and portable schema changes. This package configures the connections and adds `migrate`, `migrate:rollback`, `migrate:status`, and `make:migration`. It is not an ORM.

```sh
composer require crystoline/xpsr-database
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
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
    'slow_queries' => env('DB_SLOW_QUERIES'),   // such as "500 milliseconds"
];
```

| Setting | Meaning |
|---|---|
| `default` | The connection used by `Doctrine\DBAL\Connection` and by commands without `--connection`. |
| `connections.<name>` | [DBAL connection parameters](https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/configuration.html): `driver`, `host`, `port`, `dbname`, `user`, `password`, `charset`, `path` (SQLite), `memory` (SQLite), `driverOptions`, and others. |
| `connections.<name>.url` | A URL such as `mysql://user:secret@host:3306/app`, `postgresql://user:secret@host/app`, or `sqlite:///var/app.sqlite`. When set and not empty, its values replace the separate settings. Encode special characters in the password (`@` as `%40`). |
| `migrations.path` | Directory of migration files, relative to the project root. |
| `migrations.table` | Table that records which migrations have run. |
| `log_queries` | `true` logs every query, DBAL's and Eloquent's, at debug level through the application's PSR-3 logger, with its parameters and the time it took. Use it in development only: it logs parameters too. |
| `slow_queries` | Logs queries that take longer than this as warnings: seconds, or a period such as `'500 milliseconds'`. Unset, none are. See [Query events](#query-events). |

A relative SQLite `path` is resolved against the project root, and its directory is created if needed. Invalid settings fail when the connection is first used, with the setting's name.

Other connections come from `Crystoline\Xpsr\Database\ConnectionManager`:

```php
$reports = $connections->connection('reporting');
```

`connection()` returns the same connection each time, so code that shares it also shares its transactions. `open()` returns a new connection with the same settings. Use it for work that must stay out of the application's transactions, where other processes must see each write at once and a rollback must not undo it, such as the lock package's database store.

## After a transaction commits

Work that belongs after a transaction, such as queuing a job or sending a notification about rows that must exist by then, goes through `Crystoline\Xpsr\Database\Transactions`:

```php
use Crystoline\Xpsr\Database\Transactions;

public function __construct(
    private readonly Connection $db,
    private readonly Transactions $transactions,
    private readonly QueueInterface $queue,
) {}

$this->db->transactional(function () use ($order): void {
    $order->markPaid();
    $this->transactions->afterCommit(fn() => $this->queue->dispatch(new SendReceipt($order->id)));
});
```

The callback runs once the outermost transaction has committed: inside nested transactions (savepoints), it waits for the whole transaction, and rolling back to the savepoint it was added in drops it. A rollback drops it. Without an open transaction, it runs at once. Without a connection name, a callback waits for the transaction that began last, on any connection; `afterCommit($callback, 'reporting')` names one. `inTransaction()` tells whether a transaction is open.

A transaction's callbacks run in order. One that throws does not stop the others: its exception is thrown from `commit()`, or `transactional()`, once the last one has run, and any further exceptions are logged. The transaction has committed by then.

Transactions opened through Eloquent count too, as its connections run them through XPSR's. Connections from `open()` are outside the application's transactions, so theirs do not. Jobs from [`crystoline/xpsr-queue`](../queue/README.md#jobs-and-database-transactions) wait with `#[AfterCommit]`.

## Query events

With the event dispatcher (`Crystoline\Xpsr\Registry\EventServiceRegistry` in `app.registry`), every query on a connection dispatches a `Crystoline\Xpsr\Database\Event\QueryExecuted` event, whether DBAL or Eloquent ran it: the connection's name, the SQL as the driver got it, the values bound to it, and the seconds it took. Listen for it in `config/events.php`:

```php
'listeners' => [
    Crystoline\Xpsr\Database\Event\QueryExecuted::class => [App\Listeners\ReportSlowQueries::class],
],
```

```php
final class ReportSlowQueries
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(QueryExecuted $event): void
    {
        if ($event->seconds > 1) {
            $this->logger->warning('Slow query on {connection}: {sql}', ['connection' => $event->connection, 'sql' => $event->sql]);
        }
    }
}
```

For that case alone, `slow_queries` in the configuration does the same without a listener, and `log_queries` logs every query. Queries that fail are not reported.

## Migrations

```sh
php xpsr make:migration create_users_table --create=users
```

This writes `migrations/20261028120000_create_users_table.php`: a UTC timestamp (year, month, day, hour, minute, second), an underscore, and the name. Migrations run in file-name order, which is the order they were created. A migration file returns a `Crystoline\Xpsr\Database\Migration\Migration`, usually an anonymous class:

```php
use Crystoline\Xpsr\Database\Migration\Migration;
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
| `php xpsr make:migration <name>` | Writes a timestamped file. `--create=table` starts from a create-table migration and `--table=table` from an alter-table one. `CreateUsersTable` and `create-users-table` both become `create_users_table`. A name already used by another migration is refused. `--diff` writes the SQL that brings the database in line with an expected schema, see below. |
| `php xpsr migrate:baseline` | Writes a migration that creates every table an existing database has, and records it as run there. See [Starting from an existing database](#starting-from-an-existing-database). |

`migrate`, `migrate:rollback`, `migrate:status`, and `migrate:baseline` accept `--connection=<name>`. Each connection keeps its own migrations table.

**Generated migrations.** An ORM adapter such as `crystoline/xpsr-orm-doctrine` binds `Crystoline\Xpsr\Database\Schema\SchemaProviderInterface`, which describes the tables the application expects. `php xpsr make:migration <name> --diff` then compares the database with it and writes a migration whose `up()` runs the SQL for the difference and whose `down()` reverses it. Only tables the schema names are compared, so the migrations table and other tables are never dropped. Pending migrations must run first, and nothing is written when there is no difference. Without a schema provider, `--diff` exits with 1 and says so.

**Production.** With `APP_ENV=production`, `migrate` and `migrate:rollback` ask for confirmation in a terminal and refuse without `--force` otherwise. Deployment scripts run `php xpsr migrate --force`.

**Failures.** A migration that throws stops the run; later migrations do not run, and the command exits with 1 naming the migration. Migrations that completed before it stay applied and recorded. On PostgreSQL and SQLite, a migration runs in a transaction with its bookkeeping, so a failed migration leaves nothing behind. MySQL and MariaDB commit each schema change immediately: a migration that fails part-way stays partly applied and needs repairing by hand, so keep each migration small there. Set `protected bool $transactional = false;` in a migration whose statements cannot run in a transaction, such as PostgreSQL's `CREATE INDEX CONCURRENTLY`.

**Rolling back.** `down()` is optional. Without it, rolling back that migration fails with a message, and nothing after it in the rollback runs. A migration whose file was deleted after it ran shows as "ran, file missing" and cannot be rolled back.

### Starting from an existing database

An application that already has a database, for example one moving from Laravel, starts its migrations with a baseline:

```sh
php xpsr migrate:baseline --exclude=cache --exclude=jobs
```

1. It reads every table of the database, with its columns, indexes, unique constraints, and foreign keys, and writes `migrations/00000000000000_baseline.php`. The file builds them with DBAL's schema editors, as a hand-written migration would, so it runs on any database DBAL supports: the MySQL tables of production can be created in SQLite for tests.
2. It checks the file: the tables it builds are compared with the database's, and any difference is printed as the statements that would close it. Some details have no DBAL equivalent, such as a MySQL column type DBAL maps to another.
3. It records the baseline as run on this database, without running it: the tables are already there.

The name sorts before every other migration. On a new database, `php xpsr migrate` runs the baseline and then the later migrations. On another database that already has the tables, such as staging, `migrate:baseline` finds the file and only records it, after checking that each of its tables is there. Rolling the baseline back is refused, because it would drop every table.

- **Left out:** the migrations table, Laravel's migrations table (`id`, `migration`, `batch`), and each `--exclude` table. The data, views, triggers, stored procedures, and sequences that no auto-increment column uses are not in the baseline either; the command names the views and sequences it saw.
- **Before other migrations.** It refuses when other migrations have already run on the database: their tables would be in the baseline, and a new database would get them twice. Pending migrations are fine; they run after it.
- **Production** needs `--force`, or a confirmation in a terminal, as `migrate` does.
- **Another runner.** It works with XPSR's migrator, not with one in `database.migrations.runner`.

**Laravel's migrations table.** A Laravel database has a `migrations` table of its own, which XPSR cannot use: it has no `executed_at` column. The migration commands stop with a message when `database.migrations.table` names it. Set another name, such as `'table' => 'xpsr_migrations'` in `config/database.php`, and both lists stay side by side.

## Testing

Use an in-memory SQLite database for tests, in `config/testing/database.php`:

```php
return [
    'default' => 'testing',
    'connections' => ['testing' => ['driver' => 'pdo_sqlite', 'memory' => true]],
];
```

`Crystoline\Xpsr\Database\Testing\RefreshDatabase` gives every test a migrated, empty database, and `DatabaseAssertions` checks tables. Both need the test case's `application()` method, which the skeleton's test case has:

```php
use Crystoline\Xpsr\Database\Testing\DatabaseAssertions;
use Crystoline\Xpsr\Database\Testing\RefreshDatabase;

final class OrderTest extends TestCase
{
    use RefreshDatabase;
    use DatabaseAssertions;

    public function testAnOrderIsStored(): void
    {
        $this->postJson('/orders', ['sku' => 'A-1'])->assertCreated();

        $this->assertDatabaseHas('orders', ['sku' => 'A-1', 'status' => 'new']);
        $this->assertDatabaseMissing('orders', ['sku' => 'B-2']);
        $this->assertDatabaseCount('orders', 1);
    }
}
```

`RefreshDatabase` runs before `setUp()`. Before the first test of the process, it drops every table on the connection and runs the migrations; then it wraps each test in a transaction that is rolled back afterwards, rows seeded in `setUp()` included. On an in-memory SQLite database, which is new for every test, the migrations run each time. Override `connectionsToRefresh()` to refresh other connections as well. It refuses to run when `app.env` is `production`.

`assertDatabaseHas()`, `assertDatabaseMissing()`, `assertDatabaseCount()`, and `assertDatabaseEmpty()` take the table, the columns and values a row must have (null matches `NULL`), and a connection name other than the default. A failed `assertDatabaseHas()` shows the rows that match the first column.

SQLite differs from MySQL and PostgreSQL in places, such as type checking and some SQL functions. Point the connection at a real test database to run the suite against your production database type as well; `RefreshDatabase` migrates it once per run.

For a test whose data must be seen by other connections or processes, such as one that runs two workers against one row to prove a lock, `Crystoline\Xpsr\Database\Testing\DatabaseTruncation` commits instead: before the first test the database is dropped and migrated, and after each test (after `tearDown()`) every table but the migrations table is emptied, with auto-increment counters reset. Override `tablesToKeep()` for tables to leave alone, such as reference data. Emptying tables is slower than a rollback, so use it only where the rollback does not work.

To keep the rollback alone, on a database that is already migrated, wrap each test in a transaction with `Crystoline\Xpsr\Database\Testing\DatabaseTransactions`:

```php
use Crystoline\Xpsr\Database\Testing\DatabaseTransactions;

final class OrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        $this->beginDatabaseTransaction();           // or beginDatabaseTransaction('main', 'reporting')
    }
}
```

The transaction is rolled back after every test, together with any transactions the code under test left open. It covers everything that uses XPSR's connections. The test's transaction does not count for `Transactions::afterCommit()`, so callbacks and `#[AfterCommit]` jobs run when the code under test commits its own outermost transaction, inside the test's. MySQL and MariaDB commit implicitly on schema changes, so do not change the schema inside such a test.

## Sessions

The package provides the store for `SESSION_STORE=database`. Create its table, named by `session.database.table` (default `sessions`):

```sh
php xpsr make:session-table
php xpsr migrate
```

The table has `id`, `payload`, and an indexed `last_activity` (Unix time). `session.database.connection` picks a connection other than the default. Expired rows are deleted by `session:prune` and by about one request in `session.prune_every`. See the XPSR repository's `docs/SESSIONS.md`.

## Users and API tokens

For `config/auth.php`, the package provides the `database` user provider, which reads users from a table, the `token` guard driver: revocable API tokens stored as SHA-256 hashes, and the `sanctum` guard driver, which reads and writes Laravel Sanctum's `personal_access_tokens` table as it is (`Crystoline\Xpsr\Database\Auth\SanctumTokenRepository`), for applications moving from Laravel.

```php
'users' => ['driver' => 'database', 'table' => 'users', 'login' => 'email', 'password' => 'password'],
'guards' => ['api' => ['driver' => 'token']],
```

```sh
php xpsr make:token-table && php xpsr migrate
php xpsr auth:token ada@example.com --scope=tasks:read --expires="90 days"
php xpsr auth:token-revoke 12
```

Each token belongs to one guard (its `guard` column), and only that guard accepts it; `--guard` and the `guard` argument of `TokenRepository::create()`, `forUser()`, and `revokeAll()` choose it, defaulting to the first guard with the token driver. `Crystoline\Xpsr\Database\Auth\TokenRepository` creates, lists, and revokes tokens from code. See the XPSR repository's `docs/AUTH.md`.

## Using another migration runner

The `migrate`, `migrate:rollback`, and `migrate:status` commands talk only to `Crystoline\Xpsr\Database\Migration\MigratorInterface`. They get it from the factory named in `database.migrations.runner`, which is XPSR's own runner, `Crystoline\Xpsr\Database\MigratorFactory`, by default. A package can provide another runner, for example one backed by Doctrine Migrations, and the commands and their output stay the same:

```php
'migrations' => ['runner' => App\Database\DoctrineMigratorFactory::class],
```

A runner implements `MigratorInterface` and a factory implementing `MigratorFactoryInterface`. Prove it with the shared contract suite by extending `Crystoline\Xpsr\Database\Testing\MigratorContractTestCase` and implementing its four hooks: `migrator()`, `addMigration()`, `addFailingMigration()`, and `tableExists()`. XPSR's runner passes the same suite. `make:migration` writes files for XPSR's runner only; another runner brings its own generator.

## Services

| Service | Provides |
|---|---|
| `Doctrine\DBAL\Connection` | The default connection. Shared. |
| `Crystoline\Xpsr\Database\ConnectionManager` | Every configured connection, by name: `connection('reporting')`, or a new one with `open()`. Shared. |
| `Crystoline\Xpsr\Database\Transactions` | `afterCommit($callback)` runs code once the open transaction commits; `inTransaction()`. Shared. |
| `Crystoline\Xpsr\Database\QueryEvents` | What is done with each query: `QueryExecuted` events, `log_queries`, and `slow_queries`. ORM adapters report their queries to it. Shared. |
| `Crystoline\Xpsr\Database\Migration\MigratorFactoryInterface` | The runner the migrate commands use: `create(?string $connection)` returns a `MigratorInterface`. |
| `Crystoline\Xpsr\Database\MigratorFactory` | XPSR's own runner, for timestamped files in `migrations/`. |

This package's tests run against SQLite, and against MySQL 8.4, MariaDB 11, and PostgreSQL 17 when `XPSR_TEST_MYSQL_URL` or `XPSR_TEST_PGSQL_URL` is set.

Requires PHP 8.4+ and the PDO extension for your database.
