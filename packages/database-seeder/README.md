# crystoline/xpsr-database-seeder

Seed data for XPSR applications: the rows an application needs before anyone uses it, such as roles, plans, countries, and default settings, and sample data for development and demos.

A seeder describes what a table should hold. Running it makes the table match and changes only what differs, so the same command runs on every deployment, and running it twice changes nothing the second time. There is no record of which seeders have run, and nothing to reset: add a row to a seeder, deploy, and the row is there; change a name, deploy, and it is updated.

```sh
composer require crystoline/xpsr-database-seeder
```

Add the provider to `app.registry` in `config/app.php`, after the database provider:

```php
'registry' => [
    // ...
    Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
    Crystoline\Xpsr\Database\Seeder\SeederServiceRegistry::class,
],
```

## A first seeder

```sh
php xpsr make:seeder RolesSeeder
```

writes `app/Seed/RolesSeeder.php`. Fill in the rows:

```php
namespace App\Seed;

use Crystoline\Xpsr\Database\Seeder\Seed;
use Crystoline\Xpsr\Database\Seeder\SeederInterface;

final class RolesSeeder implements SeederInterface
{
    public function run(Seed $seed): void
    {
        $seed->table('roles')->sync('slug', [
            ['slug' => 'admin', 'name' => 'Administrator', 'can_refund' => true],
            ['slug' => 'support', 'name' => 'Support', 'can_refund' => true],
            ['slug' => 'viewer', 'name' => 'Viewer', 'can_refund' => false],
        ]);
    }
}
```

List it in a set in `config/seeds.php`:

```php
return [
    'sets' => [
        'reference' => [
            App\Seed\RolesSeeder::class,
        ],
    ],
];
```

See what it would do, then do it:

```sh
php xpsr seed --dry-run
php xpsr seed
```

```
Seeding reference
  App\Seed\RolesSeeder
    roles: 3 inserted
Seeded: 3 inserted, 0 updated, 0 deleted.
```

Run it again and it says `Nothing to change: the data is as the seeders describe it.`

## Syncing a table

`sync($key, $rows)` matches rows by a key: a column that identifies a row and that you choose, such as a slug or a code, rather than an ID the database counts.

- A row whose key is not in the table is inserted.
- A row that is there is compared column by column, and only the columns that differ are updated. Columns you do not list are left alone.
- Rows of the table that you do not list stay as they are.

```php
$result = $seed->table('plans')->sync('code', $rows);
$result->inserted;   // also updated, unchanged, deleted, and changed()
```

Values are PHP values: `true` and `false` for boolean columns, arrays for JSON columns, `DateTimeImmutable`, `DateTime`, or a date string for date columns, and backed enums for their values. The comparison knows that `"9.50"` and `9.5` are the same decimal, that JSON with its keys in another order is the same data, and that the database returns `1` for `true`, so a row is updated only when it really differs.

**A key of several columns** is a list:

```php
$seed->table('plan_prices')->sync(['plan', 'currency'], [
    ['plan' => 'pro', 'currency' => 'USD', 'amount' => 2900],
    ['plan' => 'pro', 'currency' => 'EUR', 'amount' => 2700],
]);
```

**Rows that are gone from the code** are deleted when you ask for it. Use it for tables the code owns completely:

```php
$seed->table('countries')->sync('iso', $countries, prune: true);
```

**Columns to write once.** `insertOnly` names columns that are written when a row is inserted and never compared or updated afterwards. It is for defaults that people may change, and for values that differ on every run, such as a password hash:

```php
public function __construct(private readonly PasswordHasher $passwords) {}

public function run(Seed $seed): void
{
    $seed->table('settings')->sync('name', [
        ['name' => 'invoice.due_days', 'value' => '14', 'label' => 'Days until an invoice is due'],
    ], insertOnly: ['value']);

    // The first admin: a password nobody knows, to be set through the password reset.
    $seed->table('users')->sync('email', [
        ['email' => 'admin@example.com', 'name' => 'Admin', 'password' => $this->passwords->hash(bin2hex(random_bytes(16)))],
    ], insertOnly: ['password']);
}
```

Here a later run corrects the label, and leaves the value someone set and the password the admin chose. `PasswordHasher` is the framework's `Crystoline\Xpsr\Auth\PasswordHasher`.

### Rows that refer to other rows

`map()` gives a table as a lookup, so a seeder refers to other rows by their key and never by an ID written into the code:

```php
$roles = $seed->table('roles')->map('slug');            // ['admin' => 1, 'support' => 2, ...]: slug to primary key
$rates = $seed->table('currencies')->map('code', 'rate');  // one column to another

$seed->table('users')->sync('email', [
    ['email' => 'ada@example.com', 'name' => 'Ada', 'role_id' => $roles['admin']],
]);
```

`count()` counts rows, with conditions when given: `$seed->table('users')->count(['role_id' => $roles['admin']])`.

## Sets

A set is a list of seeders that belong together. `config/seeds.php`:

```php
return [
    'sets' => [
        // Data the application needs everywhere: runs on every deployment.
        'reference' => [
            App\Seed\RolesSeeder::class,
            App\Seed\PlansSeeder::class,
        ],

        // Sample data: for development and staging, after the reference data.
        'demo' => [
            'environments' => ['local', 'staging'],
            'requires' => ['reference'],
            'seeders' => [
                App\Seed\DemoCustomersSeeder::class,
                App\Seed\DemoOrdersSeeder::class,
            ],
        ],
    ],

    'default' => 'reference',     // the set that runs when none is named; the first one when left out
    'random_seed' => 'xpsr',      // see "Sample data"
];
```

| | |
|---|---|
| `php xpsr seed` | Loads the default set. |
| `php xpsr seed demo` | Loads `reference`, which `demo` requires, and then `demo`. Several sets may be named; each runs once. |
| `php xpsr seed --only=PlansSeeder` | Runs one seeder of the sets alone, by its class name or the last part of it. |
| `php xpsr seed --dry-run` | Does everything, lists every row it would change, and changes nothing. |
| `php xpsr seed -v` | Lists the rows that changed, as the dry run does. |
| `php xpsr seed:list` | Shows the sets, their seeders, and whether this environment may run them. `--json` for scripts. |

Seeders run in the order they are listed, so a seeder comes after the ones whose rows it refers to.

A set with `environments` runs only where `APP_ENV` is one of them. Asking for it elsewhere is refused before anything is written, also when another set requires it:

```
The "demo" set is for local, staging (seeds.sets.demo.environments), and APP_ENV is production.
```

When `APP_ENV` is `production`, `seed` asks before it changes the database, as `migrate` does; `--force` answers yes for a deployment script.

## One transaction, and the dry run

A run is one transaction on each connection it writes to. It is kept whole or not at all: when a seeder fails, everything the run wrote is rolled back, the command says which seeder failed and why, and it returns 1.

`--dry-run` runs every seeder for real inside that transaction and then rolls it back, so what it reports is what a run would do, including rows that later seeders look up with `map()`. In a seeder, `$seed->isDryRun()` tells you to skip what a rollback cannot undo, such as writing a file or calling another service.

The report names rows by their key and the columns that changed, never the values, so it can go into a deployment log:

```
Seeding reference
  App\Seed\RolesSeeder
    roles: 1 inserted, 1 updated, 2 unchanged
      ~ slug=admin (name)
      + slug=auditor
Dry run, nothing was changed. A run would give: 1 inserted, 1 updated, 0 deleted.
```

MySQL and MariaDB commit on their own when the schema changes, so a seeder must not create or alter tables; that is what migrations are for.

## Sample data

Sample data should be the same for everyone on the team, and should converge like any other seed data. `Seed` gives each seeder random values that are the same on every machine and every run:

```php
public function run(Seed $seed): void
{
    $plans = array_keys($seed->table('plans')->map('code'));

    $seed->table('customers')->sync('email', $seed->times(50, fn(int $number): array => [
        'email' => "customer{$number}@example.com",
        'name' => $seed->faker()->name(),
        'plan' => $plans[$seed->random()->getInt(0, count($plans) - 1)],
        'joined_at' => new DateTimeImmutable('2026-01-01')->modify('+' . $seed->random()->getInt(0, 300) . ' days'),
    ]));
}
```

- `$seed->times($count, $row)` builds `$count` rows; `$row` gets the row's number, from 1.
- `$seed->random()` is PHP's `Random\Randomizer`: `getInt()`, `shuffleArray()`, `pickArrayKeys()`, and the rest.
- `$seed->faker()` is [Faker](https://fakerphp.org), for names, addresses, companies, and text. It needs `composer require --dev fakerphp/faker`, or without `--dev` when a set with sample data runs on a server. `$seed->faker('fr_FR')` gives another locale; `app.faker_locale` sets the default one.

Each seeder draws its own values, so adding a seeder does not change what the others produce. Another `random_seed` in `config/seeds.php` gives other data, just as repeatable. Avoid `now` and other values that change by themselves: a row with today's date is updated on every run.

For bulk rows without a natural key, fill the table once:

```php
$seed->table('page_views')->whenEmpty(fn(SeedTable $views) => $views->insert($rows));
```

`insert()` writes rows without looking at what is there, and `whenEmpty()` calls its function only when the table has no rows.

## Services, models, and other connections

Seeders are built by the container, so a constructor may ask for any service. What a seeder writes through `$seed->connection()`, or through Eloquent models, Doctrine entities, or Cycle entities on the default connection, is part of the run's transaction and is rolled back by a dry run, because the ORMs share XPSR's connections:

```php
public function run(Seed $seed): void
{
    Page::query()->updateOrCreate(['slug' => 'terms'], ['title' => 'Terms of service']);
}
```

The report counts what goes through `$seed->table()`; rows written another way are yours to make repeatable, as `updateOrCreate()` does here.

A table of another connection is named with it, and that connection gets a transaction of its own:

```php
$seed->table('event_types', 'reporting')->sync('code', $types);
$seed->connection('reporting');   // the Doctrine DBAL connection, inside the run's transaction
```

`$seed->environment()` is `APP_ENV`, for the rare row that differs by environment.

## From a database you already have

`--dump` writes the rows a table holds now into a new seeder, which turns the reference data of an existing application into code:

```sh
php xpsr make:seeder PlansSeeder --table=plans --key=code --dump
php xpsr make:seeder CountriesSeeder --table=countries --dump --except=created_at,updated_at
```

With `--key`, rows are matched by that column, and an ID the database counts is left out. Without it, rows are matched by the table's primary key and keep their IDs, which is what you want when other tables already refer to them. `--connection` reads from another connection. It is for small tables: one with more than 1000 rows is refused.

The seeder it writes describes the table as it is, so `php xpsr seed --dry-run` reports nothing to change on that database.

## Seed data or a migration?

A seeder says what rows should exist, on every run. A change that happens once, such as filling a new column from an old one or fixing rows that are wrong, is a migration: it runs once, in order with the schema change it belongs to.

## Testing

`Crystoline\Xpsr\Database\Seeder\Testing\SeedsDatabase` loads seed data in an application's tests. Beside `RefreshDatabase`, which rolls every test back, the rows are there during the test and gone after it:

```php
use Crystoline\Xpsr\Database\Seeder\Testing\SeedsDatabase;
use Crystoline\Xpsr\Database\Testing\RefreshDatabase;

final class CheckoutTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDatabase;

    protected function setUp(): void
    {
        $this->seed();                              // the default set
    }

    public function testAnOrderNeedsAPlan(): void
    {
        $this->seed(BusyShopScenario::class);       // one seeder, which need not be in a set
        // $this->seed('demo') loads a set by name, after the sets it requires.
    }
}
```

A seeder that only tests use is a good place for a scenario: the customers, orders, and payments that several tests start from. `seed()` returns the report, and a set that is not for `APP_ENV=testing` is refused, so list `testing` in the environments of the sets your tests load.

## Deployment

Run the seeders after the migrations:

```sh
php xpsr migrate --force
php xpsr seed --force
```

The command returns 0 when the data is as the seeders describe it, changed or not, and 1 when a seeder failed or a set was refused; nothing is written in that case. It works on SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17.

Two processes seeding at once, as when two servers deploy together, may both try to insert the same row; the second fails on the table's unique index, changes nothing, and succeeds when run again. Give the key columns a unique index, and run the seeders from one place, as you do the migrations.
