# crystoline/xpsr-orm-doctrine

[Doctrine ORM](https://www.doctrine-project.org/projects/orm.html) for XPSR applications. Entities are plain PHP classes mapped with attributes; the `EntityManager` runs on XPSR's database connection from `crystoline/xpsr-database`, so ORM work, plain DBAL queries, and test rollbacks share one transaction. Schema changes go through XPSR migrations, generated from the mapping with `make:migration --diff`.

```sh
composer require crystoline/xpsr-orm-doctrine
```

Add both providers to `app.registry` in `config/app.php`, database first:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry::class,
```

## Entities

Put entity classes in `app/Entity/` (`App\Entity\…`):

```php
namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tasks')]
class Task
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 200)]
        public string $title,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }
}
```

Columns are named in snake case: `createdAt` is stored as `created_at`. See Doctrine's [attribute reference](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/attributes-reference.html) for relations, indexes, and types.

Create the table from the mapping, review the generated SQL, and run it:

```sh
php xpsr make:migration create_tasks_table --diff
php xpsr migrate
php xpsr orm:validate-schema
```

Then inject `Doctrine\ORM\EntityManagerInterface`:

```php
use Doctrine\ORM\EntityManagerInterface;

public function __construct(private readonly EntityManagerInterface $entities) {}

$task = new Task('Write the docs');
$this->entities->persist($task);
$this->entities->flush();

$open = $this->entities->getRepository(Task::class)->findBy([], ['createdAt' => 'DESC'], 20);
```

## Schema changes

After changing entities, `php xpsr make:migration <name> --diff` compares the database with the mapping and writes a migration with the SQL for the difference in `up()` and its reverse in `down()`. It is an ordinary XPSR migration file: read it, edit it if needed, and commit it.

- Run pending migrations first; `--diff` refuses while migrations are pending, because the database would not reflect them.
- Only tables the entities describe are compared. The migrations table and tables you manage by hand are never dropped, so removing an entity does not drop its table: write that migration yourself.
- The SQL is for the database type of the connection. Generate migrations against the same type of database you deploy, for example MySQL in development when production uses MySQL.
- When nothing differs, no file is written.

`php xpsr orm:validate-schema` checks the mapping and whether the database matches it, comparing the same tables as `--diff`. It exits with 1 for mapping errors, 2 when the database differs, and 3 for both. `--skip-mapping` and `--skip-sync` run one check.

Doctrine's `orm:schema-tool:*` commands are not offered: they change the database directly, outside migrations.

## Configuration

`config/orm.php` is optional:

```php
return [
    'connection' => null,             // a name from database.connections; null for the default
    'entities' => ['app/Entity'],     // directories of entity classes, relative to the project root
    'metadata_cache' => 'auto',       // auto, array, or the service ID of a PSR-6 pool
    'naming' => 'underscore',         // underscore (created_at) or default (createdAt)
];
```

| Setting | Meaning |
|---|---|
| `entities` | Without this setting, `app/Entity` is used once it exists. A directory listed here must exist. |
| `metadata_cache` | `auto` caches the mapping in the application cache (`crystoline/xpsr-cache`) when it is installed and `APP_DEBUG` is off, and in memory otherwise, so edits to entities apply at once during development. `array` always uses memory. Run `php xpsr cache:clear` or `orm:clear-cache:metadata` after deploying changed entities. |
| `naming` | How property names become column names when a column has no explicit `name`. |

Unknown or malformed settings fail when the entity manager is first used, with the setting's name.

Proxy classes are not generated: Doctrine uses PHP 8.4's native lazy objects, so there are no proxy files to build or deploy.

## Commands

| Command | What it does |
|---|---|
| `orm:info` | Lists the mapped entity classes. |
| `orm:mapping:describe <entity>` | Shows the mapping of one entity. |
| `orm:validate-schema` | Checks the mapping and the database, as described above. |
| `orm:run-dql <dql>` | Runs a DQL query and prints the result. |
| `orm:clear-cache:metadata`, `:query`, `:result` | Clear Doctrine's caches. |

## Worker servers

With PHP-FPM or Apache each request starts with a fresh entity manager. A process that serves many requests must clear it between them; add the middleware first in `app.middleware`:

```php
Crystoline\Xpsr\Orm\Doctrine\Http\ClearEntityManagerMiddleware::class,
```

## Users

Entities can be the users of `config/auth.php`:

```php
'users' => ['driver' => 'doctrine', 'entity' => App\Entity\User::class, 'login' => 'email'],
```

The entity implements `Crystoline\Xpsr\Auth\UserInterface`, and `Crystoline\Xpsr\Orm\Doctrine\Auth\PasswordUpgradableInterface` to let sign-in replace outdated password hashes (the provider flushes). See the XPSR repository's `docs/AUTH.md`.

## Testing

`Crystoline\Xpsr\Database\Testing\DatabaseTransactions` from the database package covers the ORM too: the entity manager writes through the same connection, so its changes are rolled back after each test. Each test also gets a new application, and with it a new entity manager.

This adapter passes `Crystoline\Xpsr\Database\Testing\OrmAdapterContractTestCase`: it uses XPSR's connection and transaction, reads data written by plain queries, and drops its state between requests. Its tests, including generating, running, and rolling back a `--diff` migration, run against SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17.

## Services

| Service | Provides |
|---|---|
| `Doctrine\ORM\EntityManagerInterface` | The entity manager (also `Doctrine\ORM\EntityManager`). Shared. |
| `Crystoline\Xpsr\Database\Schema\SchemaProviderInterface` | The schema the entities need, used by `make:migration --diff`. |
| `Crystoline\Xpsr\Orm\Doctrine\Http\ClearEntityManagerMiddleware` | Clears the entity manager after each request. |

Requires PHP 8.4+.
