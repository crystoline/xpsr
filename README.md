# XPSR

A PHP framework in development, built around PSR interfaces and interchangeable integrations.

The framework now provides application bootstrapping, a PSR-11 container with explicit bindings, a provider register/boot lifecycle, a PSR-15 HTTP kernel, portable routing with FastRoute and Sunrise adapters, named URLs, global and route middleware, Nyholm PSR-7/17 messages and factories, Twig views, configurable error rendering with PSR-3 logging, PSR-14 events, sessions with CSRF protection, authentication with policies, and an XPSR CLI backed by standalone Symfony Console. The accepted roadmap is in [docs/FRAMEWORK_PLAN.md](docs/FRAMEWORK_PLAN.md); delivered work and remaining limitations are in [docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md).

**New to XPSR?** Follow [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) to build a small API with tests. Then read [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) before going to production. Release promises are in [docs/VERSIONING.md](docs/VERSIONING.md), and changes are in [CHANGELOG.md](CHANGELOG.md).

## Repository layout

This repository develops several Composer packages together:

| Path | Package | Contents |
|---|---|---|
| repository root | `crystoline/xpsr` | Application skeleton: `app/`, `bootstrap/`, `config/`, `routes/`, `public/`, `views/`, the `xpsr` executable, Docker setup |
| `packages/framework` | `crystoline/xpsr-framework` | Lifecycle, container, HTTP kernel, routing contracts, providers, events, console |
| `packages/router-fastroute` | `crystoline/xpsr-router-fastroute` | FastRoute adapter (the skeleton's default) |
| `packages/router-sunrise` | `crystoline/xpsr-router-sunrise` | Sunrise adapter (optional) |
| `packages/cache` | `crystoline/xpsr-cache` | Application cache: PSR-16 and PSR-6, `cache:clear`, rate limiting (optional; see [its README](packages/cache/README.md)) |
| `packages/database` | `crystoline/xpsr-database` | Database connections (Doctrine DBAL) and migrations (optional; see [its README](packages/database/README.md)) |
| `packages/orm-doctrine` | `crystoline/xpsr-orm-doctrine` | Doctrine ORM on XPSR's connection, migrations generated from entities (optional; see [its README](packages/orm-doctrine/README.md)) |
| `packages/orm-eloquent` | `crystoline/xpsr-orm-eloquent` | Eloquent models on XPSR's connection and transactions, with XPSR's migrations (optional, not published yet; see [its README](packages/orm-eloquent/README.md)) |
| `packages/queue` | `crystoline/xpsr-queue` | Queued jobs on Symfony Messenger: sync, database, Redis, AMQP, or SQS; retries and a failed jobs table (optional, not published yet; see [its README](packages/queue/README.md)) |
| `packages/mail` | `crystoline/xpsr-mail` | Email on Symfony Mailer: SMTP, sendmail, or a provider by DSN; views as bodies; queued sending (optional, not published yet; see [its README](packages/mail/README.md)) |
| `packages/scheduler` | `crystoline/xpsr-scheduler` | Scheduled tasks from one cron entry: commands, callbacks, and queued jobs, with overlap locks (optional, not published yet; see [its README](packages/scheduler/README.md)) |
| `packages/storage` | `crystoline/xpsr-storage` | File storage on Flysystem: named disks (local, S3, or your own driver), uploads, and downloads (optional, not published yet; see [its README](packages/storage/README.md)) |
| `packages/validation` | `crystoline/xpsr-validation` | Request validation: input classes, Symfony Validator rules, 422 with field errors (optional; see [its README](packages/validation/README.md)) |
| `packages/log-monolog` | `crystoline/xpsr-log-monolog` | Monolog logging: stderr, file, daily, or syslog; line or JSON; request IDs (optional; see [its README](packages/log-monolog/README.md)) |
| `packages/auth-jwt` | `crystoline/xpsr-auth-jwt` | JWT sign-in for APIs: the default `api` guard, an issuer, `auth:jwt-secret` (optional, not published yet; see [its README](packages/auth-jwt/README.md)) |
| `packages/http-client` | `crystoline/xpsr-http-client` | Outgoing HTTP: PSR-18 and named clients, a fake for tests (optional; see [its README](packages/http-client/README.md)) |

The skeleton installs the packages from `packages/` through a Composer path repository, linked into `vendor/`, so edits take effect immediately. See [docs/PACKAGES.md](docs/PACKAGES.md) for dependencies, testing, and how a release would be published.

## Development setup

Requires PHP 8.4+ and Composer 2. Development tests require the DOM, XML, XMLWriter, and mbstring extensions. On Unix, the `serve` command and live server tests also require pcntl, which is included in the Docker image. CI is configured for PHP 8.4 and 8.5.

```sh
composer install
php xpsr setup
composer serve
```

`php xpsr setup` creates `.env` from `.env.example`, asks for a router adapter (FastRoute or Sunrise) and a preset (`web` or `api`), and checks that the application boots. In scripts, pass the choices explicitly, for example `php xpsr setup --router=fastroute --preset=api --no-interaction`. See [docs/CONSOLE.md](docs/CONSOLE.md#set-up-a-project).

Open `http://localhost:3001/` for the example page (web preset) or `/hello` for the JSON example. Keep the web server's document root at `public/`. Debug output is off by default; set `APP_DEBUG=true` explicitly for local diagnostics.

If local PHP is older, use the included development container:

```sh
docker compose build
docker compose run --rm app composer install
docker compose up -d
docker compose run --rm app composer check
```

The container serves `http://localhost:8088/`. The Docker setup is for development and mounts this checkout. It is not a production deployment image.

## HTTP lifecycle

`bootstrap/app.php` returns an application with its services registered. Bootstrapping does not create an HTTP request, start a session, emit headers, or exit the process. The front controller creates the request and emits the response.

The application owns its container. Eager providers all register before their boot hooks run; deferred providers load and boot once on first use. Boot hooks can perform explicitly configured startup work. The default providers do not start sessions, and the optional session provider skips console execution.

```php
$application = require __DIR__ . '/bootstrap/app.php';
$factory = $application->get(Psr\Http\Message\ServerRequestFactoryInterface::class);
$request = $factory->createServerRequest('GET', 'http://localhost/hello');
$response = $application->getHttpKernel()->handle($request);
```

Configure PSR-15 middleware classes or instances in `config/app.php` under `middleware`. The first listed middleware runs first; responses return in reverse order. The skeleton enables body parsing, so JSON bodies, and form bodies on any method, appear in `getParsedBody()`. The kernel catches exceptions from the whole pipeline and renders JSON or HTML errors without details unless `APP_DEBUG=true`. Server errors are logged through PSR-3 to PHP's `error_log()`; set `LOG_LEVEL` or bind another logger in `config/factory.php`. See [docs/HTTP.md](docs/HTTP.md) for body parsing, error rendering, logging, and how responses are sent. Pages in `routes/web.php` have sessions and CSRF protection; see [docs/SESSIONS.md](docs/SESSIONS.md). Sign-in for browsers and APIs, and policies, are in [docs/AUTH.md](docs/AUTH.md).

Configuration loads from `config/*.php` and then `config/{APP_ENV}/*.php`, with a default environment of `production`. Environment files load before configuration; paths are resolved from the application root.

Routing defaults to FastRoute. Set `ROUTER_ADAPTER=sunrise` to switch adapters without changing route declarations. Each route file returns a callable accepting `Crystoline\Xpsr\Routing\RouteCollection`; handlers receive the server request and return a PSR-7 response. The framework handles 404/405, HEAD fallback and body suppression, and automatic OPTIONS. See [docs/ROUTING.md](docs/ROUTING.md) for route groups, constraints, middleware, named URLs, adapter contracts, and migration from the earlier Sunrise syntax.

## Console commands

```sh
php xpsr list
php xpsr about --json
php xpsr route:list
php xpsr route:list --method=GET --path=/hello --json
php xpsr app:greet Ada
php xpsr serve --port=3001
php xpsr setup --router=sunrise --preset=api --install-dependencies
php xpsr make:controller Admin/UserController
php xpsr make:middleware EnsureJsonRequest
php xpsr make:command SendReportCommand
php xpsr make:provider BillingServiceRegistry
```

The console shares application bootstrapping and returns exit codes without creating HTTP requests or starting sessions. Providers register lazy command metadata, and command constructors receive services from the container. The `make:*` generators write classes below `app/`, refuse to overwrite existing files unless given `--force`, and print the route, middleware, or registration line to add next. See [docs/CONSOLE.md](docs/CONSOLE.md) and the example in `app/Console/Commands/GreetCommand.php` for custom commands, aliases, generators, testing, and server behavior. `setup` applies router and preset choices and rolls its file changes back if the application then fails to boot.

## Container registration

Use `$application->getContainer()` to register services. Runtime consumers depend on `Psr\Container\ContainerInterface`; provider registration uses XPSR's separate builder contract.

```php
$container = $application->getContainer();
$container->bind('request.context', stdClass::class);
$container->singleton('shared.context', stdClass::class);
$container->instance('feature.enabled', false);
$container->factory('request.id', static fn () => bin2hex(random_bytes(16)));
$container->alias('context', 'shared.context');
```

`has()` does not create services. Nulls and invokable objects can be stored unchanged; factories execute only when explicitly registered as factories. Circular dependencies and provider failures produce descriptive exceptions. See [docs/CONTAINER.md](docs/CONTAINER.md) for lifetimes, autowiring rules, deferred providers, and migration from the earlier binding API.

## Checks

```sh
composer test
composer analyse
composer style
composer check
composer audit
```

`composer test` runs the framework, adapter, and skeleton test suites together. `composer style:fix` applies PER Coding Style. Static analysis (PHPStan level 5) and style checks cover all package source and tests, the application code, bootstrap, front controller, and `xpsr` executable. `composer test:packaging` installs each package on its own and builds a project with `composer create-project`. It needs network access and takes a few minutes. `composer test:coverage` additionally requires a coverage driver.

Commit `composer.lock` with application dependency changes. Keep production secrets in an untracked `.env` file or environment variables.
