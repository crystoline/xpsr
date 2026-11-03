# crystoline/xpsr-orm-cycle

[Cycle ORM](https://cycle-orm.dev/) for XPSR applications. Entities are plain PHP classes mapped with attributes; the ORM runs on XPSR's database connections from `crystoline/xpsr-database`, on the same PDO and transaction, so ORM work, plain DBAL queries, after-commit callbacks, and test rollbacks share one transaction. Schema changes go through XPSR migrations, generated from the entities with `make:migration --diff`.

```sh
composer require crystoline/xpsr-orm-cycle
```

Add both providers to `app.registry` in `config/app.php`, database first:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
Crystoline\Xpsr\Orm\Cycle\CycleOrmServiceRegistry::class,
```

## Entities

Put entity classes in `app/Entity/` (`App\Entity\…`), or run `php xpsr make:entity Task`:

```php
namespace App\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Relation\BelongsTo;
use Cycle\ORM\Entity\Behavior\CreatedAt;
use DateTimeImmutable;

#[Entity(table: 'tasks')]
#[CreatedAt(field: 'createdAt', column: 'created_at')]
class Task
{
    #[Column(type: 'primary')]
    public ?int $id = null;

    #[Column(type: 'datetime', name: 'created_at')]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(type: 'boolean', default: false)]
    public bool $done = false;

    #[BelongsTo(target: Project::class, nullable: false)]
    public ?Project $project = null;

    public function __construct(
        #[Column(type: 'string(200)')]
        public string $title,
    ) {}
}
```

Columns are named like their properties unless `name:` says otherwise; `string(200)` gives the length. See Cycle's [attribute reference](https://cycle-orm.dev/docs/annotated-entity) for relations, indexes, embeddings, and inheritance. The behaviors of `cycle/entity-behavior` work as they are: `#[CreatedAt]`, `#[UpdatedAt]`, `#[SoftDelete]`, `#[Uuid]`, `#[OptimisticLock]`, and `#[Hook]`.

Create the tables from the entities, review the generated SQL, and run it:

```sh
php xpsr make:migration create_tasks_table --diff
php xpsr migrate
```

Then inject `Cycle\ORM\ORMInterface` to read and `Cycle\ORM\EntityManagerInterface` to write:

```php
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;

public function __construct(private readonly ORMInterface $orm, private readonly EntityManagerInterface $entities) {}

$task = new Task('Write the docs');
$task->project = $project;
$this->entities->persist($task)->run();

$open = $this->orm->getRepository(Task::class)->select()->where('done', false)->orderBy('id', 'DESC')->limit(20)->fetchAll();
$task = $this->orm->getRepository(Task::class)->findByPK($id);
```

A repository of your own, named in `#[Entity(repository: TaskRepository::class)]`, extends `Cycle\ORM\Select\Repository` and gets its `Select` from Cycle and any other constructor dependency from XPSR's container. The same goes for custom mappers, sources, and behavior listeners.

## Transactions

Each XPSR connection in `config/database.php` is a Cycle database of the same name, on the PDO that XPSR's DBAL connection opened; an entity's `#[Entity(database: 'reporting')]` picks one, and the default is the default connection. Cycle's transactions run through DBAL, which nests them as savepoints, so:

- `$entities->run()` inside `$db->transactional(...)` or `Transactions::afterCommit()` commits with the outer transaction, and rolling it back undoes the ORM's writes.
- `#[AfterCommit]` queue jobs dispatched while Cycle writes run after Cycle commits.
- `DatabaseTransactions` in tests rolls Cycle's writes back with everything else.
- Cycle's queries reach `QueryExecuted` listeners, `database.log_queries`, and `database.slow_queries`, as DBAL's and Eloquent's do, and telemetry spans with `crystoline/xpsr-telemetry`.

## Schema changes

After changing entities, `php xpsr make:migration <name> --diff` compares the database with the tables the entities declare and writes a migration with the SQL for the difference in `up()` and its reverse in `down()`: an ordinary XPSR migration file to read, edit, and commit. The rules are those of the Doctrine adapter: run pending migrations first, only the entities' tables are compared, and the SQL is for the connection's database type. Cycle's own migrations package and its schema sync are not used: one migration system.

A column type the comparison cannot describe (Cycle's PostgreSQL ranges, geometry, and the like) stops `--diff` with the column's name; write that part of the migration by hand.

## The schema cache

The schema is compiled from the entity classes. With `APP_DEBUG` on, that happens on each request, so edits apply at once. For production, compile it once when deploying:

```sh
php xpsr cycle:schema:cache      # writes var/cache/cycle-schema.php
php xpsr cycle:schema            # shows the roles, tables, columns, and relations
php xpsr cycle:schema:clear      # removes the cache
```

With `APP_DEBUG` off, the cached file is read when it exists, and the entities are compiled when it does not.

## Configuration

`config/cycle.php` is optional:

```php
return [
    'connection' => null,             // Cycle's default database: a name from database.connections, or null for the default
    'entities' => ['app/Entity'],     // directories of entity classes, relative to the project root
    'schema_cache' => 'auto',         // auto, a file path, or null to compile on each request
];
```

| Setting | Meaning |
|---|---|
| `connection` | The default database, and the one `make:migration --diff` compares. |
| `entities` | Without this setting, `app/Entity` is used once it exists. A directory listed here must exist. |
| `schema_cache` | `auto` uses `var/cache/cycle-schema.php` when it exists and `APP_DEBUG` is off. A path names another file, used whenever it exists. `null` always compiles. |

Unknown or malformed settings fail when the ORM is first used, with the setting's name. Dates are converted to `app.timezone` on the way to the database.

## Commands

| Command | What it does |
|---|---|
| `make:entity <Name>` | Writes an entity class in `app/Entity`, for the table Cycle would choose (`Task`: `tasks`). |
| `cycle:schema [--json]` | Shows the compiled schema. |
| `cycle:schema:cache` | Compiles the schema to the cache file. |
| `cycle:schema:clear` | Removes the cache file. |
| `make:migration <name> --diff` | From the database package: the migration for the difference between the database and the entities. |

## Worker servers

With PHP-FPM or Apache each request starts with an empty heap. A process that serves many requests must forget the entities it loaded between them; add the middleware first in `app.middleware`:

```php
Crystoline\Xpsr\Orm\Cycle\Http\ClearHeapMiddleware::class,
```

## Users

Entities can be the users of `config/auth.php`:

```php
'users' => ['driver' => 'cycle', 'entity' => App\Entity\User::class, 'login' => 'email'],
```

The entity implements `Crystoline\Xpsr\Auth\UserInterface`, and `Crystoline\Xpsr\Orm\Cycle\Auth\PasswordUpgradableInterface` to let sign-in replace outdated password hashes (the provider saves the entity). See the XPSR repository's `docs/AUTH.md`.

## With the other packages

- **Audit log.** An entity marked `#[Audited]` from `crystoline/xpsr-audit` is recorded on insert, update, and deletion, inside the same transaction, with the attribute's `except` and `redact`.
- **API pagination.** `Pagination::paginate()` from `crystoline/xpsr-api` pages a `Select`: `$this->pagination->fromRequest($request)->paginate($repository->select()->orderBy('id'))`.

## Testing

`Crystoline\Xpsr\Database\Testing\DatabaseTransactions` from the database package covers the ORM too: the entity manager writes through the same connection, so its changes are rolled back after each test. Each test also gets a new application, and with it an empty heap.

This adapter passes `Crystoline\Xpsr\Database\Testing\OrmAdapterContractTestCase`: it uses XPSR's connection and transaction, reads data written by plain queries, and drops its state between requests. Its tests, including generating, running, and rolling back a `--diff` migration, run against SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17.

## Services

| Service | Provides |
|---|---|
| `Cycle\ORM\ORMInterface` | The ORM: `getRepository()`, `make()`, the schema and the heap. Shared. |
| `Cycle\ORM\EntityManagerInterface` | `persist()`, `delete()`, `run()`. Shared. |
| `Cycle\Database\DatabaseManager` | Cycle's databases, one per XPSR connection. Shared. |
| `Cycle\ORM\SchemaInterface` | The compiled schema. |
| `Crystoline\Xpsr\Database\Schema\SchemaProviderInterface` | The tables the entities need, used by `make:migration --diff`. |
| `Crystoline\Xpsr\Orm\Cycle\Http\ClearHeapMiddleware` | Forgets the loaded entities after each request. |
| `auth.users.cycle` | The user provider factory `AuthManager` uses for `'driver' => 'cycle'`. |

## Not provided

Cycle's migrations package and `cycle:sync`, read and write replicas, and the schema renderer. Drivers other than PDO's (`pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `pdo_sqlsrv`) cannot share a connection.

Requires PHP 8.4+.
