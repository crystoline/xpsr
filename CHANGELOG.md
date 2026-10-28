# Changelog

All XPSR packages share one version number. See [docs/VERSIONING.md](docs/VERSIONING.md) for what each kind of release promises.

## Unreleased

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
