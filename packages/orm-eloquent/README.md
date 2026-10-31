# crystoline/xpsr-orm-eloquent

[Eloquent](https://laravel.com/docs/eloquent) models for XPSR applications, without the rest of Laravel. Models run on XPSR's database connections from `crystoline/xpsr-database`: Eloquent queries the PDO that XPSR's connection opened, and its transactions go through XPSR's, so models, plain DBAL queries, and test rollbacks share one transaction. Schema changes are XPSR migrations.

```sh
composer require crystoline/xpsr-orm-eloquent
```

Add both providers to `app.registry` in `config/app.php`, database first:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry::class,
```

There is nothing else to configure: connections are the ones in `config/database.php`.

## Models

```sh
php xpsr make:model Task
php xpsr make:migration create_tasks_table --create=tasks
```

`make:model` writes `app/Model/Task.php` (`App\Model\Task`) for the table Eloquent would choose (`tasks`):

```php
namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'tasks';
    protected $fillable = ['title', 'done'];
    protected $casts = ['done' => 'boolean'];
}
```

```php
$task = Task::query()->create(['title' => 'Ship it']);
$open = Task::query()->where('done', false)->orderBy('id')->get();
$task->update(['done' => true]);
```

Everything Eloquent offers works: relationships, casts, scopes, accessors, soft deletes, and model events and observers. A model with `protected $connection = 'reporting';` uses the XPSR connection named `reporting`. The connections have an event dispatcher (`Illuminate\Events\Dispatcher`, a service), so `$db->listen()` and `whenQueryingForLongerThan()` work, and every query Eloquent runs is reported to the database package's [query events](../database/README.md#query-events), `log_queries`, and `slow_queries`, as DBAL's are.

Two features need more packages: `paginate()` needs `illuminate/pagination`, and model factories need `fakerphp/faker`. Without them, use `forPage($page, $perPage)` and plain test data.

## Schema changes

Tables are created and changed with XPSR migrations (`make:migration`, `migrate`, `migrate:rollback`), written with Doctrine DBAL's schema API; see the database package's README. Eloquent's own migrations and schema builder are not used, so every database adapter shares one set of migration commands. Eloquent has no mapping to compare with the database, so `make:migration --diff` is for Doctrine entities only.

## Transactions

```php
use Illuminate\Database\ConnectionInterface;

$db->transaction(static function () {
    $order = Order::query()->create([...]);
    $order->lines()->createMany([...]);
});
```

Eloquent's transactions run through the XPSR connection, which nests them with savepoints. An Eloquent transaction inside an XPSR one (as in `DatabaseTransactions` tests), or the reverse, never starts a second transaction on the same PDO, and rolling back the outer one undoes everything inside it. `transaction($callback, attempts: 3)` retries deadlocks at the outermost level, as in Laravel.

Code that must run once the transaction has committed, and not if it rolls back, goes through `Crystoline\Xpsr\Database\Transactions::afterCommit()`, which follows Eloquent's transactions because they run through XPSR's; see [the database package](../database/README.md#after-a-transaction-commits). Eloquent's own `afterCommit()` needs Laravel's transaction manager, which is not set, and throws. Jobs from `crystoline/xpsr-queue` wait with `#[AfterCommit]`.

## Users

Models can be the users of `config/auth.php`:

```php
'users' => ['driver' => 'eloquent', 'model' => App\Model\User::class, 'login' => 'email', 'password' => 'password'],
```

```php
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Orm\Eloquent\Auth\Authenticatable;

class User extends Model implements UserInterface
{
    use Authenticatable;

    protected $hidden = ['password'];
}
```

`Authenticatable` reads the key and the `password` attribute; sign-in saves an upgraded hash to the `password` column. See the XPSR repository's `docs/AUTH.md`.

## Encrypted attributes

Eloquent's encrypted casts use XPSR's encrypter with `APP_KEY` (`php xpsr key:generate --write`): `encrypted`, `encrypted:array`, `encrypted:collection`, `encrypted:object`, `AsEncryptedArrayObject`, and `AsEncryptedCollection`.

```php
protected function casts(): array
{
    return ['api_secret' => 'encrypted', 'bank_details' => 'encrypted:array'];
}
```

Values are stored in Laravel's format, so columns that a Laravel application encrypted with the same `APP_KEY` read as they are, and Laravel reads what XPSR writes. Keep the columns `TEXT`: a payload is about 1.8 times the value's length plus about 200 characters. With `APP_PREVIOUS_KEYS` set, old values still decrypt, and assigning an attribute re-encrypts it with the current key on save. Without a key, the application starts, and the first encrypted attribute says how to make one. See [docs/ENCRYPTION.md](../../docs/ENCRYPTION.md).

## Testing

`Crystoline\Xpsr\Database\Testing\DatabaseTransactions` from the database package covers models too: they write through the same connection, so their changes are rolled back after each test.

This adapter passes `Crystoline\Xpsr\Database\Testing\OrmAdapterContractTestCase`. Its tests, including transactions nested both ways, run against SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17.

Static analysis tools do not see Eloquent's static forwarding (`Task::where(...)`); `Task::query()->where(...)` is the same call and is understood.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Orm\Eloquent\ConnectionResolver` | Eloquent's connections by XPSR connection name (also `Illuminate\Database\ConnectionResolverInterface`). Shared. |
| `Illuminate\Database\ConnectionInterface` | The default connection, for the query builder: `$db->table('tasks')->count()`. |
| `Illuminate\Events\Dispatcher` | The dispatcher of model and connection events (also `Illuminate\Contracts\Events\Dispatcher`). Shared. |

Requires PHP 8.4+ and a PDO driver: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, or `pdo_sqlsrv`.
