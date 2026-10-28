# Upgrading

Steps for moving an application between minor versions. What changed is in [CHANGELOG.md](CHANGELOG.md); what each kind of release promises is in [docs/VERSIONING.md](docs/VERSIONING.md).

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
