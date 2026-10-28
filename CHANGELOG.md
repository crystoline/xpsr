# Changelog

All XPSR packages share one version number. See [docs/VERSIONING.md](docs/VERSIONING.md) for what each kind of release promises.

## Unreleased

### Breaking

- **Every class moves from `Crystoline\` to `Crystoline\Xpsr\`**, for example `Crystoline\Application` to `Crystoline\Xpsr\Application`, `Crystoline\Cache\CacheServiceRegistry` to `Crystoline\Xpsr\Cache\CacheServiceRegistry`, and `Crystoline\Router\FastRoute\FastRouteRouter` to `Crystoline\Xpsr\Router\FastRoute\FastRouteRouter`. The vendor namespace is no longer taken by one product, and it matches the `crystoline/xpsr-*` package names. There are no aliases for the old names.

  To upgrade an application, change its requirements to `^0.2`, rewrite the references in its own code and configuration, and update:

  ```sh
  grep -rlE 'Crystoline\\' app bootstrap config public routes tests \
      | xargs perl -pi -e 's/\bnamespace Crystoline;/namespace Crystoline\\Xpsr;/g; s/\bCrystoline(\\+)(?!Xpsr\b)/Crystoline${1}Xpsr${1}/g'
  composer update 'crystoline/*'
  ```

  The second pattern keeps the backslash style of each reference, so it also rewrites names written with double backslashes, such as `ROUTER_ADAPTER` class names in strings. Check the result with `composer test`.

### Added

- PSR-14 events: `Crystoline\Xpsr\Events\EventDispatcher`, `ListenerProvider`, and the optional `StoppableEvent` base class, registered by `Crystoline\Xpsr\Registry\EventServiceRegistry`. Listeners are listed in `config/events.php` or added by providers with a priority. See [docs/EVENTS.md](docs/EVENTS.md).
- `php xpsr event:list`, `make:event`, and `make:listener`.
- New projects list `EventServiceRegistry` in `app.registry` and include `config/events.php`. Existing projects can add both to use events.
- `crystoline/xpsr-http-client`: an optional package providing a configured PSR-18 client, named clients (`http_client.<name>`) with their own base URL and headers, Symfony's `HttpClientInterface`, and `Testing\FakeClient` for tests. Options live in `config/http_client.php`. See [its README](packages/http-client/README.md).
- `swap($id, $replacement)` in the application `tests/TestCase.php`, to replace a service in a test.
- `crystoline/xpsr-database`: an optional package providing Doctrine DBAL connections configured in `config/database.php`, and migrations with `migrate`, `migrate:rollback`, `migrate:status`, and `make:migration`. In production, migrations need `--force` or a confirmation. See [its README](packages/database/README.md).
- Database extension points: the migrate commands use `MigratorInterface`, chosen with `database.migrations.runner`, so another runner can replace XPSR's; `Testing\MigratorContractTestCase` checks a runner; `Testing\DatabaseTransactions` rolls back each test's database writes.
- `crystoline/xpsr-orm-doctrine`: an optional Doctrine ORM 3 adapter. The `EntityManager` runs on XPSR's connection, with attribute-mapped entities in `app/Entity`, snake-case columns, PHP 8.4 native lazy objects instead of proxy classes, and the application cache for metadata in production. Includes Doctrine's read-only and cache commands, an XPSR `orm:validate-schema`, and `ClearEntityManagerMiddleware` for worker servers. See [its README](packages/orm-doctrine/README.md).
- `php xpsr make:migration <name> --diff` writes a migration from the difference between the database and an expected schema, such as Doctrine's entity mapping. Only tables the schema names are compared. `Schema\SchemaProviderInterface` lets an ORM adapter supply the schema; `Testing\OrmAdapterContractTestCase` checks ORM adapters.
- CORS: `Crystoline\Xpsr\Http\Middleware\CorsMiddleware`, configured in `config/cors.php` and `CORS_ALLOWED_ORIGINS`. It answers preflight requests, adds the headers to every response from an allowed origin, error responses included, and supports subdomain patterns. New projects list it first in `app.middleware`; it is inactive until an origin is allowed. See [docs/HTTP.md](docs/HTTP.md#cors).
- PSR-20 clock: `Psr\Clock\ClockInterface` is registered as a `symfony/clock` `NativeClock` in `app.timezone`. The application `TestCase` gains `freezeTime()`.

### Changed

- The `database_path()` helper is removed. It pointed at a `database/` directory XPSR does not use; migrations live in `migrations/`.
- The skeleton's `config/database.php` is replaced: the previous file was unused and came from another framework. The new one configures SQLite, MySQL, and PostgreSQL connections for `crystoline/xpsr-database`.

### Fixed

- Route building is 18 to 48 times faster. Route patterns were parsed again on every use, and constraints re-validated each time, so 300 routes took about 276 ms per request; they now take about 6 ms. `RouteCollection::named()` and URL generation no longer scan every route.

## 0.1.1 (2026-10-28)

### Fixed

- `php xpsr about` and `php xpsr --version` report the installed framework version, such as `0.1.1`, instead of always saying `development`.

### Deprecated

- `Crystoline\Console\ConsoleKernel::VERSION`. Use `ConsoleKernel::version()`. The constant still exists and still equals `development`.

## 0.1.0 (2026-10-28)

First release of the rebuilt framework. It replaces the earlier prototype, which was published as `crystoline/xpsr` `v1.0.0`; there is no upgrade path from it. Start a new project and move application code across.

### Added

- Packages: `crystoline/xpsr` (skeleton), `crystoline/xpsr-framework`, `crystoline/xpsr-router-fastroute`, and `crystoline/xpsr-router-sunrise`.
- Application bootstrap with `.env` loading and per-environment configuration overrides (`config/{APP_ENV}/`).
- PSR-11 container with explicit bindings, autowiring, aliases, and eager or deferred service providers with register/boot stages.
- PSR-15 HTTP kernel, global and route middleware, body parsing for JSON and non-POST forms, and a response emitter.
- Portable route definitions with groups, names, constraints, defaults, and URL generation, served by the FastRoute or Sunrise adapter. HEAD, OPTIONS, 404, and 405 are handled.
- JSON or HTML error rendering by content negotiation; exception details only with `APP_DEBUG=true`. Server errors are logged through PSR-3 (`error_log()` by default).
- Forwarding headers trusted only from `TRUSTED_PROXIES`.
- `xpsr` console with `about`, `route:list`, `serve`, `setup`, and the `make:controller`, `make:middleware`, `make:command`, and `make:provider` generators.
- Application test scaffold (`tests/TestCase.php`) with request and console helpers.
- Router contract test suite for adapter authors (`Crystoline\Testing\RouterContractTestCase`).
- Documentation: getting started, deployment, HTTP, routing, container, console, packages, and versioning.

### Cache

- `crystoline/xpsr-cache`: an optional package providing the application cache as PSR-16 and PSR-6, backed by Symfony Cache, with `file`, `array`, `null`, and `service` drivers and a `cache:clear` command.

### HTTP exceptions

- `Crystoline\Http\Exception\*`: `HttpException`, `HttpExceptionInterface`, and classes for 400, 401, 403, 404, 405, 409, 413, 415, 422, 429, and 503. The framework no longer depends on `symfony/http-kernel` or `symfony/http-foundation`. Symfony's HttpKernel exceptions are still rendered with their status when that package is installed.

### Optional dependencies

- `twig/twig` (views) and `filp/whoops` (debug error pages) are suggestions of the framework, not requirements. The skeleton requires Twig for its web example and installs Whoops for development only. Without Whoops, debug mode shows a plain built-in error page.

### Requirements

- PHP 8.4 or 8.5.
