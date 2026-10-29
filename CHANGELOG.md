# Changelog

All XPSR packages share one version number. See [docs/VERSIONING.md](docs/VERSIONING.md) for what each kind of release promises.

## Unreleased

### Fixed

- HTTP/1.0 requests are answered with HTTP/1.0. Every response used to say HTTP/1.1. Under Apache with mod_php, that kept the connection open until the keep-alive timeout, 5 seconds by default. HTTP/1.0 clients that read until the connection closes, such as some health checks and monitoring tools, waited that long for every response. Responses to HTTP/1.1 and HTTP/2 requests are unchanged.

## 0.4.0 (2026-10-29)

One change can affect existing code (see **Breaking**). Upgrade steps are in [UPGRADE.md](UPGRADE.md#from-03-to-04).

### Breaking

- **A missing view throws `Crystoline\Xpsr\View\ViewNotFoundException`** instead of Twig's `LoaderError`. Code that catches `Twig\Error\LoaderError` for a missing template must catch the new exception instead.

### Added

- View engines next to Twig, chosen with `app.view_engine` (`VIEW_ENGINE`): `crystoline/xpsr-view-plates` (native PHP templates), `crystoline/xpsr-view-latte` (context-aware escaping), and `crystoline/xpsr-view-blade` (Blade through `illuminate/view`, with `@csrf` and the template functions on `$xpsr`), or a `ViewHandlerInterface` class of your own. See [docs/VIEWS.md](docs/VIEWS.md).
- `Crystoline\Xpsr\View\ViewFunctionsInterface`, through which the view provider gives every engine `csrf_token()`, `csrf_field()`, `session()`, `auth_user()`, and `can()`; `ViewNotFoundException`; and `Crystoline\Xpsr\Testing\ViewHandlerContractTestCase`, the behavior every engine must have.
- `crystoline/xpsr-storage`: file storage on Flysystem 3. Named disks in `config/storage.php`, each a `FilesystemOperator` from `StorageManager::disk()`; drivers `local`, `memory`, and `s3` (with `league/flysystem-async-aws-s3`, for S3 and compatible services), and an application's own as `storage.driver.<name>`. `store()` saves an upload under a random name with an extension from its content; `download()` streams a file as a response; `fake()` swaps a disk for memory in tests. See [its README](packages/storage/README.md).
- `crystoline/xpsr-scheduler`: scheduled tasks from one cron entry. `routes/schedule.php` lists console commands, callbacks (services injected by type), and queued jobs with frequencies such as `daily('03:00')`, `weekly('monday', '08:00')`, `everyMinutes(15)`, or `cron()`, limited with `weekdays()`, `weekends()`, `on()`, `when()`, and `skip()`, in `app.timezone` or a task's own `timezone()`. `withoutOverlapping()` locks through Symfony Lock (files in `var/locks` by default, or a shared store). `php xpsr schedule:run`, `schedule:list`, and `schedule:work`. See [its README](packages/scheduler/README.md).
- `crystoline/xpsr-mail`: email on Symfony Mailer, configured by `mail.dsn` (`MAIL_DSN`): SMTP, sendmail, provider bridges, `log://` (the default, written to the application log), and `array://` for tests. `Mailer::compose()` renders a view as the HTML body with a text version; `send()` fills in `mail.from`; `queue()` sends from a `crystoline/xpsr-queue` worker. `php xpsr mail:test`. See [its README](packages/mail/README.md).
- `crystoline/xpsr-queue`: queued jobs on Symfony Messenger. Jobs implement `JobInterface` and handle themselves (`handle()` gets services by type); `QueueInterface::dispatch()` with a delay or transport; transports `sync://`, `in-memory://`, `database://`, and Redis, AMQP, or SQS; retries with growing delays, then a `failed_jobs` table; `php xpsr queue:work`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:restart`, `make:job`, and `make:queue-table`; `FakeQueue` for tests. See [its README](packages/queue/README.md).
- `TableMigrationCommand` gains `stubPath()` and `replacements()`, for table migrations in other packages.
- `crystoline/xpsr-orm-eloquent`: Eloquent models on XPSR's database connections, sharing their transactions, with `php xpsr make:model` and the `eloquent` user provider for `config/auth.php`. Schema changes stay XPSR migrations. See [its README](packages/orm-eloquent/README.md).
- `StubGenerator::withStubDirectory()`, for `make:*` commands in other packages.
- Authentication in the framework (`Crystoline\Xpsr\Auth\`): `AuthManager` with `attempt()`, `verify()`, `login()`, `logout()`, and `intended()`; guards configured in `config/auth.php`; the session guard for browsers; `PasswordHasher` (Argon2id or bcrypt, upgraded at sign-in); `Identity::of($request)`; the middleware `Authenticate` (required, optional, or per guard), `Guest`, and `RequireScopes`; 401 and 403 errors with `WWW-Authenticate` challenges. See [docs/AUTH.md](docs/AUTH.md).
- Several kinds of user: named providers in `auth.providers`, a `provider` and its own `login_path` and `home_path` per guard, a sign-in per session guard (`attempt(..., guard: 'admin')`, `logout($request, 'admin')`, `Guest::guard('admin')`), and API tokens bound to their guard (the JWT `guard` claim and the token table's `guard` column). Policy methods typed for one kind of user refuse the others.
- Authorization: `Gate` with policies per model class, `before()`, defined abilities, and `authorize()` (403); the Twig functions `auth_user()` and `can()`.
- `crystoline/xpsr-auth-jwt`: JWTs for APIs on `lcobucci/jwt`, the default driver of the `api` guard, with `JwtIssuer`, HS256 to HS512, RS256, and ES256, and `php xpsr auth:jwt-secret` and `auth:jwt`. See [its README](packages/auth-jwt/README.md).
- In `crystoline/xpsr-database`: users from a table (`auth.users` driver `database`), and revocable API tokens (guard driver `token`) with `TokenRepository`, `php xpsr make:token-table`, `auth:token`, and `auth:token-revoke`.
- In `crystoline/xpsr-orm-doctrine`: users as entities (`auth.users` driver `doctrine`).
- `TestCase::actingAs()` in application tests.
- Sessions in the framework (`Crystoline\Xpsr\Session\`): `SessionMiddleware` and `Session::of($request)` with values, flash values, `regenerate()`, and `invalidate()`; file and array stores; `config/session.php`; `SessionServiceRegistry`; and `php xpsr session:prune`. Sessions do not use PHP's `session_start()`, store only JSON data, and set a cookie only once they hold something. See [docs/SESSIONS.md](docs/SESSIONS.md).
- CSRF protection: `CsrfMiddleware` (403 without the session's token in `_token` or `X-CSRF-Token`), and the Twig functions `csrf_field()`, `csrf_token()`, and `session()`.
- Session stores in packages: `CacheSessionStore` in `crystoline/xpsr-cache` (`SESSION_STORE=cache`), and `DatabaseSessionStore` with `php xpsr make:session-table` in `crystoline/xpsr-database` (`SESSION_STORE=database`). `Crystoline\Xpsr\Testing\SessionStoreContractTestCase` checks custom stores.
- `Crystoline\Xpsr\Http\Cookie`, for `Set-Cookie` headers with safe defaults.
- `ResponseBuilder::redirect()`, 303 by default, and `ViewHandler::addFunction()`.
- Application tests keep cookies between requests, and `TestCase` gains `postForm()`, `csrfToken()`, `withSession()`, `session()`, and `send()`.

### Changed

- New projects have `view_engine` in `config/app.php`.
- New projects list `AuthServiceRegistry` in `app.registry`, have `config/auth.php`, and run `Authenticate::optional()` in the `routes/web.php` group. `.env.example` has `AUTH_API_DRIVER` and `AUTH_JWT_SECRET`.
- New projects list `SessionServiceRegistry` in `app.registry`, and `routes/web.php` puts its routes in a group with `SessionMiddleware` and `CsrfMiddleware`. The skeleton's example `App\Http\Middleware\SessionMiddleware` and `App\Registry\SessionServiceRegistry`, which did not work, are removed, and `config/session.php` takes new options.
- `crystoline/xpsr-cache` and `crystoline/xpsr-database` require `psr/clock`.
- Every package's `composer.json` links to the documentation website, [xpsr.crysto.name.ng](https://xpsr.crysto.name.ng).

## 0.3.0 (2026-10-28)

Nothing breaks; upgrade steps are in [UPGRADE.md](UPGRADE.md#from-02-to-03).

### Added

- `crystoline/xpsr-log-monolog`: an optional package that makes Monolog the application logger, writing to stderr, a file, daily files, or syslog (`LOG_CHANNEL`), as lines or JSON (`LOG_FORMAT`, JSON by default in production), with a request ID on every line from `RequestIdMiddleware`, which also echoes it in `X-Request-Id`. See [its README](packages/log-monolog/README.md).
- Rate limiting in `crystoline/xpsr-cache`: named sliding-window limits in `config/rate_limit.php`, attached with `->middleware(RateLimit::named('login'))` or through `app.middleware`. Clients are counted by address through the trusted proxies, or by a custom key resolver. Over the limit, 429 with `Retry-After`; `X-RateLimit-Limit` and `X-RateLimit-Remaining` on every response. See [its README](packages/cache/README.md#rate-limiting).
- Request validation. The framework defines `Crystoline\Xpsr\Validation\ValidatorInterface`, `ValidationException` (422 with field errors, shown in every environment), and `InputMapper`, which maps the body, query string, or route parameters onto input classes: field names from `#[Field]` and `#[Input(naming: 'snake')]`, filters (`Trim`, `Lowercase`, `Uppercase`, `EmptyToNull`, `Replace`, custom `#[Filter]`), and type conversion reported as field errors. The new `crystoline/xpsr-validation` package implements the rules with Symfony Validator and registers `make:input`. See [its README](packages/validation/README.md).
- `Crystoline\Xpsr\Http\Exception\PublicErrorInterface`: an error whose message and details the exception handler shows to clients in every environment.
- `Crystoline\Xpsr\Http\ClientIp`: the client address of a request, read through the trusted proxies' `X-Forwarded-For` chain.

## 0.2.0 (2026-10-28)

Upgrade steps are in [UPGRADE.md](UPGRADE.md#from-01-to-02).

### Breaking

- **Every class moves from `Crystoline\` to `Crystoline\Xpsr\`**, for example `Crystoline\Application` to `Crystoline\Xpsr\Application`, `Crystoline\Cache\CacheServiceRegistry` to `Crystoline\Xpsr\Cache\CacheServiceRegistry`, and `Crystoline\Router\FastRoute\FastRouteRouter` to `Crystoline\Xpsr\Router\FastRoute\FastRouteRouter`. The vendor namespace is no longer taken by one product, and it matches the `crystoline/xpsr-*` package names. There are no aliases for the old names; `UPGRADE.md` has a command that rewrites them.
- **The `database_path()` helper is removed.** It pointed at a `database/` directory XPSR does not use; migrations live in `migrations/`. The 0.1 skeleton's `config/database.php` calls it, so replace or delete that file when upgrading.

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
