# Upgrading

Steps for moving an application between minor versions. What changed is in [CHANGELOG.md](CHANGELOG.md); what each kind of release promises is in [docs/VERSIONING.md](docs/VERSIONING.md).

## From 0.4 to 0.5

Nothing breaks. Projects with rate limits have one thing to check, in step 2; the other steps are optional. Run the steps from the project root.

### 1. Require 0.5

In `composer.json`, change every `crystoline/xpsr-*` requirement from `^0.4` to `^0.5`, then run:

```sh
composer update 'crystoline/*' --with-dependencies
composer test
```

### 2. Rate limits

Rate limits now lock each count, so parallel requests can no longer pass a limit together. Unless the lock package (step 4) keeps its locks in Redis or the database, the locks are files in `var/locks`. The web server must be able to write there. The directory is created on first use; if the web server cannot create directories in `var/`, create `var/locks` for it. Otherwise limited requests fail with "Rate limits keep their locks in …, which could not be created."

With several servers and a shared cache, install the lock package with a store they share, such as Redis. File locks only stop parallel requests on the same server.

### 3. Cache compiled templates (optional)

New projects keep compiled Twig, Latte, and Blade templates instead of compiling them on every request. To do the same, set this in `config/app.php`:

```php
'view_cache_dir' => dirname(__DIR__) . '/var/cache/views',
```

The web server user must be able to write to `var/cache/views`. A changed template is compiled again on its own, even with `app.debug` off. If your deploy keeps the files' original times, for example by unpacking an archive or with `rsync -a`, add `php xpsr view:clear` to it. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#writable-directories).

### 4. Locks (optional)

To take locks across processes and servers, `composer require crystoline/xpsr-lock` and add `Crystoline\Xpsr\Lock\LockServiceRegistry::class` to `app.registry`. Its README shows `config/lock.php`, with Redis, Redlock, or the database for several servers.

The scheduler's `withoutOverlapping()` then uses the same locks, unless `config/scheduler.php` sets `lock`. To share them, remove the `lock` line, or change it to `'lock' => env('SCHEDULER_LOCK'),`. Rate limits use them too.

### 5. Encryption (optional)

To encrypt data, or to use Eloquent's encrypted casts:

1. Add this line after `'key' => env('APP_KEY'),` in `config/app.php`, for key rotation:

   ```php
   'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_PREVIOUS_KEYS', ''))))),
   ```

2. Run `php xpsr key:generate --write`, and add `APP_KEY=` to `.env.example`. Coming from Laravel, copy its `APP_KEY` instead, so its encrypted data still reads.

See [docs/ENCRYPTION.md](docs/ENCRYPTION.md).

### 6. Laravel Sanctum tokens (optional)

For an application that moves from Laravel, a guard with `'driver' => 'sanctum'` accepts the tokens in Laravel's `personal_access_tokens` table. See [docs/AUTH.md](docs/AUTH.md#laravel-sanctum-tokens).

### 7. Notifications (optional)

To send notifications by email, SMS, an in-app inbox, or browser push, `composer require crystoline/xpsr-notifications`, add `Crystoline\Xpsr\Notifications\NotificationServiceRegistry::class` to `app.registry`, and create `config/notifications.php` as [its README](packages/notifications/README.md) shows. Coming from Laravel, keep its `VAPID_*` keys, and set `database.table` and `webpush.table` to its tables if their names differ.

## From 0.3 to 0.4

0.4 adds sessions, sign-in, more view engines, and packages for queues, mail, scheduled tasks, file storage, and Eloquent. One change can affect existing code (step 2); everything after it is optional. Run the steps from the project root.

### 1. Require 0.4

In `composer.json`, change every `crystoline/xpsr-*` requirement from `^0.3` to `^0.4`, then run:

```sh
composer update 'crystoline/*' --with-dependencies
composer test
```

### 2. Views

1. A missing view now throws `Crystoline\Xpsr\View\ViewNotFoundException`. Code that caught `Twig\Error\LoaderError` for one catches that instead.
2. Optional: to use Plates, Latte, or Blade, `composer require crystoline/xpsr-view-<engine>` and add `'view_engine' => env('VIEW_ENGINE', 'twig')` to `config/app.php`, then set it. Without the key, Twig is used.

See [docs/VIEWS.md](docs/VIEWS.md).

### 3. Sessions and CSRF (optional)

1. Add `Crystoline\Xpsr\Registry\SessionServiceRegistry::class` to `app.registry` in `config/app.php`.
2. Replace `config/session.php` with the new skeleton's version. The old file's `lifetime` was a number of minutes; it is now a period such as `'2 hours'`, and `SESSION_LIFETIME=120` must become `SESSION_LIFETIME="2 hours"`.
3. Put browser routes in a group with the middleware, in `routes/web.php`:

   ```php
   use Crystoline\Xpsr\Session\CsrfMiddleware;
   use Crystoline\Xpsr\Session\SessionMiddleware;

   $routes->group('', static function (RouteCollection $routes): void {
       // your web routes
   }, middleware: [SessionMiddleware::class, CsrfMiddleware::class]);
   ```

   Add `{{ csrf_field() }}` to every form those routes receive.
4. Delete the 0.x skeleton's `app/Http/Middleware/SessionMiddleware.php` and `app/Registry/SessionServiceRegistry.php` if you never changed them. They did nothing useful; the first imported classes from other projects.
5. Add `<env name="SESSION_STORE" value="array" force="true"/>` to the `<php>` section of `phpunit.xml`, and copy the new `tests/TestCase.php` for its cookie and session helpers.

See [docs/SESSIONS.md](docs/SESSIONS.md).

### 4. Authentication (optional)

1. Add `Crystoline\Xpsr\Registry\AuthServiceRegistry::class` to `app.registry`, after `SessionServiceRegistry`, and copy `config/auth.php` from the new skeleton.
2. For browsers, add `Authenticate::optional()` to the group in `routes/web.php`, after `CsrfMiddleware`, and `Authenticate::class` to pages for signed-in users.
3. For APIs, `composer require crystoline/xpsr-auth-jwt`, add `Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry::class` to `app.registry`, and run `php xpsr auth:jwt-secret --write`. Or set `AUTH_API_DRIVER=token` and run `php xpsr make:token-table`.
4. Copy `tests/TestCase.php` for `actingAs()`.

See [docs/AUTH.md](docs/AUTH.md).

### 5. Other new packages (optional)

Each is installed with `composer require` and adds a provider to `app.registry` in `config/app.php`. Its README has the configuration.

- **Queued jobs:** `crystoline/xpsr-queue`, with `Crystoline\Xpsr\Queue\QueueServiceRegistry` after the database provider. See [its README](packages/queue/README.md).
- **Email:** `crystoline/xpsr-mail`, with `Crystoline\Xpsr\Mail\MailServiceRegistry`. See [its README](packages/mail/README.md).
- **Scheduled tasks:** `crystoline/xpsr-scheduler`, with `Crystoline\Xpsr\Scheduler\SchedulerServiceRegistry`, and one cron entry that runs `php xpsr schedule:run` every minute. See [its README](packages/scheduler/README.md).
- **File storage:** `crystoline/xpsr-storage`, with `Crystoline\Xpsr\Storage\StorageServiceRegistry`. See [its README](packages/storage/README.md).
- **Eloquent:** `crystoline/xpsr-orm-eloquent`, with `Crystoline\Xpsr\Database\DatabaseServiceRegistry` and then `Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry`. See [its README](packages/orm-eloquent/README.md).

## From 0.2 to 0.3

0.3 only adds features, so the upgrade is a version change. Run the steps from the project root.

### 1. Require 0.3

In `composer.json`, change every `crystoline/xpsr-*` requirement from `^0.2` to `^0.3`, then run:

```sh
composer update 'crystoline/*' --with-dependencies
composer test
```

### 2. Optional: use the new features

None of these is needed to keep an application working.

- **Validation:** `composer require crystoline/xpsr-validation`, add `Crystoline\Xpsr\Validation\Symfony\ValidationServiceRegistry` to `app.registry`, and create input classes with `php xpsr make:input`. See [its README](packages/validation/README.md).
- **Monolog logging:** `composer require crystoline/xpsr-log-monolog` and add `Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry` to `app.registry`. See [its README](packages/log-monolog/README.md).
- **Rate limiting:** with `crystoline/xpsr-cache` installed, add `config/rate_limit.php` and attach `RateLimit::named('...')` to routes. See [the cache README](packages/cache/README.md#rate-limiting).

## From 0.1 to 0.2

Run the steps from the project root, then `composer test`.

### 1. Require 0.2

In `composer.json`, change every `crystoline/xpsr-*` requirement from `^0.1` to `^0.2`. Do not run `composer update` yet: the next steps must come first, or the console cannot start.

### 2. Rename `Crystoline\` to `Crystoline\Xpsr\`

Every framework class moved, for example `Crystoline\Application` to `Crystoline\Xpsr\Application` and `Crystoline\Router\FastRoute\FastRouteRouter` to `Crystoline\Xpsr\Router\FastRoute\FastRouteRouter`. Rewrite the references in your code and configuration:

```sh
grep -rlE 'Crystoline\\' app bootstrap config public routes tests \
    | xargs perl -pi -e 's/\bnamespace Crystoline;/namespace Crystoline\\Xpsr;/g; s/\bCrystoline(\\+)(?!Xpsr\b)/Crystoline${1}Xpsr${1}/g'
```

The command keeps the backslash style of each reference, so class names inside strings, such as a custom `ROUTER_ADAPTER`, are rewritten too. Add any other directories that mention XPSR classes. There are no aliases for the old names.

### 3. Replace `config/database.php`

The `database_path()` helper is gone, and the 0.1 skeleton's `config/database.php` calls it. Every configuration file loads on each request, so this step is required even if you never used a database:

- If you do not use `crystoline/xpsr-database`, delete `config/database.php`.
- If you will, replace it with the [0.2 version](config/database.php), which configures SQLite, MySQL, and PostgreSQL for the new package.

Also replace any `database_path(...)` calls in your own code, for example with `root_dir('database/...')`.

### 4. Update

```sh
composer update 'crystoline/*'
composer test
```

### 5. Optional: new features

These are off unless you add them; new projects have them.

- **Events.** Add `Crystoline\Xpsr\Registry\EventServiceRegistry::class` to `app.registry` in `config/app.php`, before `ConsoleServiceRegistry`, and create `config/events.php` with `'listeners' => []`. See [docs/EVENTS.md](docs/EVENTS.md).
- **CORS.** Add `Crystoline\Xpsr\Http\Middleware\CorsMiddleware::class` first in `app.middleware`, copy [`config/cors.php`](config/cors.php), and set `CORS_ALLOWED_ORIGINS`. See [docs/HTTP.md](docs/HTTP.md#cors).
- **Clock.** `Psr\Clock\ClockInterface` is available without changes. Copy `freezeTime()` from the [0.2 `tests/TestCase.php`](tests/TestCase.php), together with `swap()`, to use them in your tests.
- **Packages.** `crystoline/xpsr-database`, `crystoline/xpsr-orm-doctrine`, and `crystoline/xpsr-http-client` are new; see their READMEs.
