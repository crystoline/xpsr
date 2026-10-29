# XPSR implementation status

The proposal and its default decisions were accepted on 26 October 2026. This file tracks implementation separately from the original assessment in `FRAMEWORK_PLAN.md`.

## First milestone: completed — bootstrap and testable HTTP handling

Implemented:

- Completed references to the `Crystoline` namespace across the application, helpers, and configuration.
- Set the PHP baseline to 8.4+, updated dependencies, and made the application lockfile trackable.
- Added a shared bootstrap with absolute resource paths and environment-specific configuration overrides.
- Made service bootstrapping idempotent and independent of HTTP request creation and output.
- Added a PSR-15 HTTP kernel with global middleware, short-circuit responses, and exception handling.
- Kept the Sunrise router while replacing abandoned HTTP-message support packages with Nyholm PSR-7/17 and its request-from-globals integration.
- Added response helpers that use injected PSR-17 factories, 404/405 responses, and the `Allow` header.
- Added production-safe exception rendering and PSR-3 reporting; debug detail requires explicit configuration.
- Moved response emission to the HTTP front controller and added a fallback for bootstrap failures.
- Added PHPUnit tests, PHPStan, scoped PER formatting, and a PHP 8.4/8.5 CI matrix.
- Updated the Docker development environment to PHP 8.4 and a `public/` document root.

Verified on 26 October 2026:

- PHPUnit: 12 tests and 70 assertions pass on PHP 8.4.27 and PHP 8.5.12.
- PHPStan level 5 passes for the configured maintained surface.
- PER style check passes for all 18 configured files.
- Composer strict validation and installation from the lockfile pass; the dependency audit reports no security advisories.
- The included PHP 8.4 Docker image builds successfully.
- Apache HTTP checks pass for `/` (200), `/hello` (200 JSON), `/missing` (404), and `POST /hello` (405 with `Allow`). `/composer.json` returns 404 through the public document root.
- PHP syntax checks pass across source, application, configuration, routes, helpers, bootstrap, front controller, and tests.
- The temporary Apache verification container was stopped after testing. CI configuration has been added; no remote CI run is claimed.

## Second milestone: completed — container and provider lifecycle

Implemented:

- Replaced application inheritance with container composition. The application accepts a container implementing the framework registration contract, exposes `getContainer()`, and keeps read helpers for compatibility.
- Upgraded `psr/container` to 2.0.2 and separated the registration/builder interface from runtime PSR-11 consumption.
- Added explicit class, singleton, factory, stored-value, and alias definitions. Nulls and invokable services are preserved; shared factory results are cached even when null.
- Made `has()` check availability without constructing services or loading deferred providers.
- Added constructor autowiring with defaults, nullable and parent/self handling, descriptive unsupported-type errors, and no session-based injection.
- Added circular-resolution diagnostics, correct missing-service versus dependency-failure exceptions, preserved causes, and cache invalidation when bindings change.
- Added ordered provider registration/boot stages, duplicate suppression, deferred groups loaded once, advertised-service validation, sticky failure handling, and recursive-boot detection.
- Migrated built-in providers to explicit factories. The optional session provider now starts in its boot hook and skips console execution.
- Expanded maintained static analysis and formatting to the container, contracts, provider manager, providers, and tests.
- Documented the new API and migration rules in `CONTAINER.md`.

Verified on 26 October 2026: 60 tests and 211 assertions pass on PHP 8.4.27 and 8.5.12. PHPStan level 5, PER style checks for 50 files, PHP syntax checks, Composer strict validation, and the dependency security audit pass. These are local results; no remote CI run is claimed.

Apache checks also pass after the composition refactor: `/` and `/hello` return 200, an unknown route returns 404, and `POST /hello` returns 405 with an `Allow` header. The temporary verification container was stopped afterward.

## Third milestone: completed — portable routing

Implemented:

- Added framework-owned route definitions and collections with names, methods, constraints, URL defaults, nested static-prefix groups, middleware, and validation before freezing.
- Replaced the dispatch-only router contract with `match(): RouteMatch`; separated named URL generation behind `UrlGeneratorInterface`.
- Added FastRoute 1.3.1 and Sunrise adapters using the same definitions and matching policy. FastRoute is now the default; `ROUTER_ADAPTER=sunrise` selects the migration adapter.
- Moved controller/service resolution and route middleware execution into XPSR using PSR-11 and PSR-15. Handlers receive a server request and must return a PSR-7 response.
- Added stable precedence, decoded route attributes, single-pass segment encoding, distinct 404/405 behavior, combined `Allow` methods, explicit/implicit HEAD, and automatic or explicit OPTIONS.
- Applied HEAD body suppression after global middleware and exception rendering, so short-circuit and error responses obey the same policy.
- Migrated example route files to callable collection definitions, removed the old native-router wrapper, and expanded static/style checks to routing.
- Documented the grammar, extension contract, HTTP policies, URL generation, and breaking API migration in `ROUTING.md`.

Verified on 26 October 2026: 117 tests and 422 assertions pass on PHP 8.4.27 and PHP 8.5.12 with no reported test issues. The shared adapter suite covers both routers; application tests switch adapters using unchanged route files. PHPStan level 5, PER style checks for 68 files, all-source PHP syntax checks, Composer strict validation, and the security audit pass.

Apache checks pass with FastRoute: welcome and JSON routes return 200, unknown routes return 404, unsupported methods return 405 with `Allow`, HEAD returns no body for success and missing routes, and automatic OPTIONS returns 204 with `Allow` for existing paths or 404 for missing paths. `/composer.json` remains outside the public document root. The temporary verification container was stopped after testing. These are local checks; no remote CI run is claimed.

## Fourth milestone: completed — console foundation

Implemented:

- Added a project-root `xpsr` executable and `Application::getConsoleKernel()` using standalone Symfony Console 7.4 as a direct runtime dependency.
- Added explicit provider-based command registration with names, aliases, collision checks, and lazy PSR-11 service resolution. Included `app:greet` as a constructor-injection example.
- Added `list`, `help`, completion support, `about` with selected runtime/configuration information, and portable `route:list` with filters and JSON output.
- Added `serve` using Symfony Process with validated host/port options, argument-array execution, an absolute public document root, and a development router script that supports dynamic paths containing dots.
- Added stdout/stderr handling, bootstrap failure reporting, exit-code propagation, repeated console invocation coverage, and Unix signal forwarding/child cleanup. The console kernel itself does not exit.
- Added pcntl to the development image and CI extensions for Unix server shutdown; `composer serve` now uses the XPSR command.
- Documented registration, command lifecycle, output, testing, development server behavior, and remaining tooling in `CONSOLE.md`.

Verified on 26 October 2026: all 154 tests and 573 assertions pass on PHP 8.4.27 and PHP 8.5.12, with pcntl enabled and no skipped tests. PHPStan level 5, PER style checks for 84 files, all-source PHP syntax checks including the executable, Composer strict validation, and the dependency audit pass. The updated PHP 8.4 development image builds successfully.

Subprocess tests verify invocation from another working directory, bootstrap failures on stderr, and exit status propagation. Live development-server tests verify paths containing spaces, dynamic URLs containing dots, static public files, rejection of hidden/outside files, occupied-port errors, and child shutdown after both SIGINT and SIGTERM. Temporary applications and server processes are cleaned up by the tests. These are local results; no remote CI run is claimed.

## Fifth milestone: completed — code generators

Implemented:

- Added `make:controller` (with `--invokable`), `make:middleware`, `make:command` (with `--command` and `--description`), and `make:provider`, built on a shared `AbstractMakeCommand` and a `StubGenerator` service. Stubs ship in `src/Crystoline/Console/Resources/stubs/`.
- Added `app.namespace` and `app.source_dir` configuration so the framework does not hard-code the `App\` namespace or `app/` path.
- Class names accept `/` or `\` for subnamespaces and are validated as ASCII PHP identifiers, rejecting reserved PHP words and Windows device names.
- Writes stay inside the source directory after resolving symbolic links. A symbolic link target is never replaced. Existing files are reported as conflicts unless `--force` is given. New files are created exclusively, and forced replacements use a temporary file plus rename.
- Missing names are asked for interactively and fail as usage errors with `--no-interaction`. Exit codes: 0 success, 2 invalid input (nothing written), 1 conflict or filesystem failure. Messages go to stderr.
- Generators print the next manual step (route, middleware, command registration, or `app.registry` entry), because registration remains explicit. `make:command` warns when the console name is already registered and writes options as PHP string literals.
- `CommandRegistry::NAME_PATTERN` is now the single command-name rule shared by registration and `make:command`.
- Documented generator usage, configuration, safety rules, and exit codes in `CONSOLE.md`.

Verified on 26 October 2026: all 214 tests and 806 assertions pass on PHP 8.4.27 (development image) and PHP 8.5.12 (official CLI image with pcntl), with no skipped tests. PHPStan level 5 and PER style checks for 95 files pass.

Integration tests generate every class type into a temporary application whose path contains a space, then run the generated code: both controller forms answer HTTP requests through the generated middleware, the generated provider boots, and the generated command runs. Separate tests cover conflicts and `--force`, invalid names for every generator, symbolic-link escapes, unfilled stub placeholders, command-option escaping, interactive prompting, and lazy listing. Generated files from the real project executable also pass PER style and PHPStan at the highest level; those temporary files were removed afterwards. These are local results; no remote CI run is claimed.

## Sixth milestone: completed — setup and installation choices

Implemented:

- Added `php xpsr setup` with `--router=fastroute|sunrise`, `--preset=web|api`, `--install-dependencies`, `--composer`, and `--dry-run`. Choices are asked for interactively; omitted options keep the current setting, so re-running setup is idempotent.
- Moved `sunrise/http-router` (and its `fig/http-message-util` dependency) from `require` to `require-dev` plus `suggest`, at unchanged versions. FastRoute remains the production default. Selecting Sunrise runs `composer require` only with `--install-dependencies` or after confirmation. Packages installed only through `require-dev` do not count as present.
- `.env` is created from `.env.example` or edited in place: only `ROUTER_ADAPTER` changes. Presets set the route files in `config/routing.php`, which is rewritten only while it is still an unmodified skeleton file. Customized files and symbolic links are refused, with the manual change printed.
- Order of operations: validate, plan, install, write, check. Planning or Composer failures leave files untouched. A bootstrap check in a fresh PHP process boots the application, resolves the router, and matches every static route without running handlers. If it fails, or the effective adapter or route files differ from the choice (for example because of a shell `ROUTER_ADAPTER`), every written file is restored.
- Child processes no longer inherit values this process loaded from `.env`, which would otherwise override the newly written file.
- Documented setup in `CONSOLE.md`, the dependency change in `ROUTING.md`, and the new first-run steps in the README.

Verified on 26 October 2026: all 241 tests and 941 assertions pass on PHP 8.4.27 and PHP 8.5.12, with no skipped tests. PHPStan level 5, PER style checks for 105 files, all-source PHP syntax checks, Composer strict validation, and the dependency audit pass.

Fresh-project tests copy the skeleton into a directory whose path contains spaces and run the real executable non-interactively. For both presets and both adapters, the project boots, `about` reports the chosen adapter, `route:list` output is identical across adapters, and `/hello` answers through `public/index.php`. Other tests cover idempotent re-runs, `.env` preservation, refused customized configuration, missing-package refusal, Composer failure and a missing Composer, rollback after a failed check, a shell environment override, a kept custom adapter, invalid options, dry runs, and interactive prompts. A Composer stand-in records the exact `composer require` arguments; a real Composer install during setup was not exercised by the tests. These are local results; no remote CI run is claimed.

## Seventh milestone: completed — HTTP pipeline release gates

Implemented:

- Replaced the untested `ResponseWriter` with `Crystoline\Xpsr\Http\ResponseEmitter`:
  - Repeated headers become separate lines; `Set-Cookie` never replaces earlier cookies.
  - The response's status is passed with every header and the status line goes last, so `Location` cannot turn into 302. There is no trailing space when the reason phrase is empty.
  - A valid `Content-Length` caps the body. Streamed bodies are flushed per chunk. 1xx/204/205/304 send no body.
  - If output has already started, it throws instead of silently dropping headers.
- Added `BodyParsingMiddleware`, enabled in the skeleton:
  - JSON bodies (`application/json`, `application/*+json`) are parsed on every method; large integers are kept as strings.
  - URL-encoded forms are parsed on methods other than POST.
  - Malformed or scalar JSON returns 400; bodies over 1 MiB return 413. The raw body stays readable.
- 404 and 405 are now thrown as Symfony HTTP exceptions and rendered by the exception handler. They get the same negotiated JSON/HTML body as other errors; 405 keeps `Allow`. This is a behavior change for middleware that inspected empty 404 responses.
- Replaced the default `NullLogger` with `ErrorLogLogger`, a PSR-3 logger writing through `error_log()` with a `LOG_LEVEL` threshold. Server errors are no longer discarded by default.
- The front controller logs failures outside the kernel and returns a plain 500 only if headers have not been sent.
- Documented the pipeline, body parsing, errors, logging, and emission in `HTTP.md`.

Verified on 26 October 2026: all 282 tests and 1,119 assertions pass on PHP 8.4.27 and PHP 8.5.12, with no skipped tests. The server-based suites (front controller, development server, setup) passed three further consecutive runs. PHPStan level 5 (now including the front controller and factory configuration) and PER style checks for 113 files pass.

The front-controller suite serves a skeleton copy with PHP's built-in server and checks raw HTTP responses:

- custom status reasons, repeated headers and cookies, a 301 that stays 301, 204, streamed bodies, HEAD;
- JSON 404s and body parsing (URL-encoded POST/PUT, JSON PATCH, multipart POST, malformed JSON);
- production errors (no details, logged with trace), debug errors, bootstrap failure, and output that starts before emission.

Apache in the PHP 8.4 development image also returned the expected 200, bodyless HEAD, JSON 404, 405 with `Allow`, 400 for malformed JSON, and 204 for OPTIONS, and kept `composer.json` unreachable. The temporary container was removed. Apache error-log delivery of application errors was not separately exercised because the skeleton has no failing route. These are local results; no remote CI run is claimed.

## Eighth milestone: completed — package separation

Implemented:

- Removed the unused legacy classes: custom request classes and traits, `HttpRequestFactory`, `HeaderSecurity`, `RouteHandlerInterface`, and `ApplicationHandlerInterface`. Their earlier versions remain in Git history.
- Split the code into Composer packages developed in this repository, with the root as the application skeleton (`crystoline/xpsr`):
  - `crystoline/xpsr-framework` (`packages/framework`): everything previously under `src/Crystoline`, plus the global helpers.
  - `crystoline/xpsr-router-fastroute` and `crystoline/xpsr-router-sunrise`: the adapters, now `Crystoline\Xpsr\Router\FastRoute\FastRouteRouter` and `Crystoline\Xpsr\Router\Sunrise\SunriseRouter`.
  - The skeleton requires the framework and FastRoute adapter through a path repository. Sunrise is a suggestion and a development requirement.
- The framework names the official adapters only as strings, so it has no dependency on either. A missing adapter fails with the setup command to run. `setup` now installs the adapter packages, using `--update-with-dependencies` so Composer can move `psr/http-message` to the major version Sunrise needs.
- The framework accepts `psr/http-message` 1.1 or 2.0. Only the Sunrise adapter pins 1.x.
- The shared routing suite is now `Crystoline\Xpsr\Testing\RouterContractTestCase`, shipped with the framework. Each adapter package runs it from its own tests; third-party adapters can do the same.
- Tests moved with their code: framework unit tests to `packages/framework/tests`, adapter contract tests to each adapter, and skeleton integration tests stay in `tests/`. Each package has its own `phpunit.xml.dist`, README, license, and strict-valid `composer.json`.
- Added `scripts/export-skeleton.php`, which produces the releasable skeleton without monorepo-only configuration, and a `packaging` test group (`composer test:packaging`) with a matching CI job.
- PHPStan level 5 and PER style checks now cover all package source and tests and the application code, instead of a hand-picked list. Newly covered files received formatting-only fixes; `helpers.php` received type declarations, a corrected docblock, and a `function_exists` guard for `join_paths()`.
- Documented the layout, dependency rules, versions, tests, export, and remaining publishing steps in `PACKAGES.md`.

Verified on 26 October 2026: all 282 tests and 1,119 assertions pass on PHP 8.4.27 and PHP 8.5.12. PHPStan level 5 over the full surface, PER style checks for 122 files, syntax checks, and strict validation of all four `composer.json` files pass. Locked third-party versions are unchanged by the split; only the three local packages were added.

The packaging group (4 tests, 45 assertions) passed on PHP 8.4.27 with network access:

- Each package installed alone from its own `composer.json` passes its own tests. The framework did so with `psr/http-message` 2.0.
- `composer create-project` built a working application from the exported skeleton with only the FastRoute adapter installed.
- In that application, `setup` performed a real `composer require` of the Sunrise adapter, downgrading `psr/http-message` from 2.0 to 1.1; routes, a request through `public/index.php`, and `make:controller` worked afterwards.

That test found the `psr/http-message` conflict, which plain `composer require` could not resolve; the setup command now passes `--update-with-dependencies`.

Not verified: the packaging group on PHP 8.5 (the local PHP 8.5 image has no Composer or zip support; the CI job covers it). The dependency audit could not be re-run because Packagist's advisory service timed out repeatedly. Since no third-party version changed, the earlier clean audit applies to the same set. No remote CI run is claimed.

## Ninth milestone: completed — release readiness without publishing

Implemented:

- **Application test scaffold.** The skeleton now has a test scaffold: `tests/TestCase.php` (requests through the full HTTP kernel, `get`/`postJson`, and console commands), example tests in `tests/Application/`, and a `phpunit.xml`. The exported skeleton includes them, with PHPUnit as its only development requirement and a `composer test` script.
- **Minimum dependency versions.** Running the whole suite at the lowest allowed versions showed that `vlucas/phpdotenv` 5.4, `nyholm/psr7` 1.8.0, `filp/whoops` 2.14, and `symfony/var-dumper` 6.4 print PHP 8.4 deprecation notices. Those notices corrupted HTTP responses and console JSON in 12 tests. The minimums are now phpdotenv `^5.6.1`, Nyholm PSR-7 `^1.8.2`, and Whoops `^2.16` (later `^2.18.4`, see below), with a framework `conflict` on `symfony/var-dumper` below 7.4. Locked versions did not change.
- **Deprecations fail tests.** PHPUnit now fails on deprecations in every configuration. A new CI job runs the whole suite at the lowest allowed versions on PHP 8.4 and 8.5. `XPSR_DEPENDENCIES=lowest` does the same for the per-package packaging tests.
- **Trusted proxies.** `Forwarded` and `X-Forwarded-*` headers are honored only from `TRUSTED_PROXIES` (IP addresses or CIDR ranges). Previously, the request library trusted `X-Forwarded-Proto` from any client. The framework now declares `symfony/http-foundation`, which it uses for the IP matching.
- **Wider packaging test.** It now also runs the created project's own tests and checks a `--no-dev --optimize-autoloader` production install.
- **Documentation.** Added `GETTING_STARTED.md` (a tasks API built step by step), `DEPLOYMENT.md`, `VERSIONING.md` (lockstep versions, what the public API covers, 0.x and 1.x rules), and `CHANGELOG.md`. Classes outside the public API are marked `@internal`.

Verified on 26 October 2026:

- **Test suite:** 292 tests and 1,154 assertions pass on PHP 8.4.27 and PHP 8.5.12. PHPStan level 5, PER style checks for 125 files, syntax checks, strict validation of all four `composer.json` files, and the Composer security audit pass.
- **Lowest versions:** the whole suite passes at the lowest allowed dependency versions on PHP 8.4.27 and PHP 8.5.12, with deprecations treated as failures. The packaging group passes with current dependencies (4 tests) and with lowest dependencies (3 per-package tests).
- **Tutorial:** walked through in a project created with `composer create-project`. The generators, the tutorial's files, 9 passing tests, `route:list`, the command, and live requests through `php xpsr serve` (list, JSON 404, 201 with the key, 401 without) all behaved as documented. Switching to Sunrise afterwards left all 9 tests passing.

Not verified: the nginx example in `DEPLOYMENT.md` (Apache and PHP's built-in server are tested). No remote CI run is claimed.

## Tenth milestone: completed — publishing automation

Implemented:

- `scripts/split.sh <remote-base> <branch> [tag]` publishes the committed `HEAD` to four read-only repositories:
  - framework and both adapters through `git subtree split`, with history;
  - the skeleton from `scripts/export-skeleton.php`, one sync commit per change.

  Branch syncs are idempotent. A release tag pushes tags only and leaves branches alone.
- A `split` CI job runs the script after every other job passes. It runs only in `crystoline/xpsr`, on `master` and `v*` tags, with a `SPLIT_TOKEN` secret.
- `export-skeleton.php --release=X.Y.Z` writes release constraints (`^X.Y`) for the XPSR packages. The skeleton ships without `composer.lock`, replacing the plan's skeleton-lockfile item; the reason is in `PACKAGES.md`.
- Every package, and the skeleton, declares the branch alias `dev-master` → `0.1.x-dev`.
- **Fixed:** `setup` hard-coded `^0.1@dev`, so after a release it installed an adapter's `dev-master` instead of the matching release. It now derives the constraint from the installed framework version (`^0.1` for a release, `^0.1@dev` for development installs).
- Documented the publishing flow, the token, and the owner-only first-publication steps in `PACKAGES.md`, and the release steps in `VERSIONING.md`.
- **Fixed:** the previous commit (`80af75c`) added `symfony/http-foundation: ^7.4`, but the lock holds 8.1.9, allowed through `symfony/http-kernel`. That left `composer.lock` out of date, which strict validation, and therefore CI, would reject. The constraint is now `^7.4 || ^8.0`; the suite passes on 8.1.9 (current) and 7.4.0 (lowest). No locked version changed.
- Added fast tests for `export-skeleton.php`, including `--release` and invalid usage, to the normal suite.

Verified on 26 October 2026: 296 tests and 1,205 assertions pass on PHP 8.4.27. The suite before the export tests (293 tests) also passed on PHP 8.5.12 and at the lowest dependency versions. PHPStan, PER style checks for 126 files, strict validation of all four `composer.json` files, and the packaging group (4 tests) pass.

Also verified, against local bare repositories standing in for GitHub:

- Two branch splits produced identical commits, and the skeleton was unchanged on the second run.
- A `v0.1.0` release tagged all four repositories. The skeleton's `master` kept `^0.1@dev`, and its tag required `^0.1`.
- `composer create-project` from those repositories (as VCS repositories) installed framework and FastRoute `0.1.0`, and the application's tests passed.
- `setup --router=sunrise --install-dependencies` installed the Sunrise adapter `0.1.0` and moved `psr/http-message` to 1.1; the tests still passed.
- A `0.1.x-dev` project resolved the `master` branches through the branch aliases.

Not verified: the CI `split` job itself (it needs the organization's repositories and secret), pushing over HTTPS with a token, and Packagist's handling of the split repositories.

## Eleventh milestone: completed — optional Twig and Whoops

Implemented:

- **Optional dependencies.** `twig/twig` and `filp/whoops` are no longer framework requirements; the framework suggests them.
  - The skeleton requires Twig, because its web example renders views. It installs Whoops as a development dependency, so `composer install --no-dev` leaves Whoops out.
  - API-only applications can `composer remove twig/twig`. The view provider is deferred, so Twig loads only when a view is rendered, and it then fails with `composer require twig/twig` in the message.
- **Built-in debug page.** Without Whoops, debug HTML errors use a built-in page: each exception in the chain with its class, location, and stack trace, all HTML-escaped. `ExceptionHandler` takes an optional `$whoops` argument; `null` means "use it when installed".
- **Fixed.** Whoops renders nothing under the CLI SAPI by default, so debug HTML responses had empty bodies in application tests and in CLI-based servers. The handler now asks Whoops to render unconditionally.
- **Lock file.** Whoops moved to the development section at the same version (2.18.7); no other locked version changed.

Verified on 26 October 2026: 300 tests and 1,219 assertions pass on PHP 8.4.27 and PHP 8.5.12, and at the lowest allowed dependency versions with deprecations as failures. PHPStan, PER style checks for 127 files, strict validation of all four `composer.json` files, the security audit, and the packaging group pass. In the packaging group, the framework installs and tests standalone without Twig or Whoops as requirements, and a `--no-dev` application install serves requests without Whoops.

## Twelfth milestone: completed — no Symfony HttpKernel dependency

Implemented:

- **XPSR HTTP exceptions.** `Crystoline\Xpsr\Http\Exception`:
  - `HttpExceptionInterface` and `HttpException`, which rejects statuses outside 400–599;
  - classes for 400, 401 (with the `WWW-Authenticate` challenge), 403, 404, 405 (with `Allow`), 409, 413, 415, 422, 429 (optional `Retry-After`), and 503.

  Constructors follow Symfony's argument order (`$message`, `$previous`, `$headers`, `$code`), which keeps migration simple.
- **Framework code switched.** The route dispatcher, body parser, and tutorial use them. The exception handler renders XPSR exceptions and, when `symfony/http-kernel` is installed, Symfony's too, so existing code keeps working.
- **`IpRange`** replaces Symfony's `IpUtils` for trusted-proxy matching: exact addresses and CIDR ranges, IPv4 and IPv6, with invalid ranges never matching.
- **Smaller install.** The framework no longer requires `symfony/http-kernel` or `symfony/http-foundation`. A fresh production install of the skeleton no longer contains HttpKernel, HttpFoundation, EventDispatcher, ErrorHandler, VarDumper, or `psr/event-dispatcher`. Those packages remain development dependencies of the monorepo, which runs the Symfony compatibility test, at unchanged versions.

Verified on 26 October 2026:

- **Suite:** 342 tests and 1,297 assertions pass on PHP 8.4.27 and PHP 8.5.12, and at the lowest allowed dependency versions with deprecations as failures.
- **Static checks:** PHPStan, PER style checks for 143 files, strict validation of all four `composer.json` files, and the security audit pass.
- **Packaging:** the packaging group passes, including the framework's standalone tests.
- **Tutorial:** walked through again in a freshly created project, with the same results.
- **Fresh install:** its `--no-dev` install contains only Console, Process, and Finder (plus their contracts and polyfills) from Symfony, and serves a JSON 404 correctly.

## Thirteenth milestone: completed — optional application cache

Implemented:

- **New package.** `crystoline/xpsr-cache` (`packages/cache`, `Crystoline\Xpsr\Cache\`) is a fifth package. `CacheServiceRegistry` provides one application cache as PSR-16 and PSR-6 over the same storage, backed by Symfony Cache.
- **Drivers:**
  - `file` (default, `var/cache/app`);
  - `array` (per process);
  - `null` (stores nothing);
  - `service`, which uses any PSR-6 pool bound in the container. This is the route to Redis, APCu, or Memcached without the package claiming untested drivers.
- **Configuration.** Options live in `config/cache.php` (`driver`, `path`, `namespace`, `default_ttl`, `service`). Invalid values fail on first use with the setting's name.
- **`php xpsr cache:clear`.** It is registered only when the console is present, and exits 1 if storage reports a failure.
- **Optional.** The skeleton does not install the package; applications run `composer require crystoline/xpsr-cache` and add the provider. The monorepo, split script, packaging tests, PHPUnit, and PHPStan include it; the skeleton export leaves it out.
- **Compatibility fix.** Requiring `psr/simple-cache` 3 made the package impossible to install next to the Sunrise adapter, whose router library needs 1.x. The package accepts versions 1 to 3, and its tests handle the 1.x exception interfaces, which do not extend `Throwable`.
- **Packaging fix.** Installed alone, the package also needed a PSR-17 implementation to satisfy the framework. It now has `nyholm/psr7` as a development dependency, as the adapters do.

Verified on 26 October 2026:

- **Suite:** 358 tests and 1,335 assertions pass on PHP 8.4.27 and PHP 8.5.12, and at the lowest allowed dependency versions with deprecations as failures.
- **Static checks:** PHPStan, PER style checks for 146 files, strict validation of all five `composer.json` files, and the security audit pass.
- **Packaging:** the group passes with current dependencies (5 tests, including the cache package installed alone) and with lowest dependencies (4 per-package tests).
- **Not tested:** Redis, APCu, or Memcached pools through the `service` driver.

**First GitHub CI run (27 October 2026).** It failed. GitHub's `setup-php` uses the production `php.ini`, while local runs had none. Reproducing that locally showed two problems:

- **Stray output.** With `output_buffering` on, stray output before the response was buffered rather than sent, so the emitter did not notice and the text ended up in front of the body. The emitter now also detects output waiting in a buffer, and the front controller discards it and returns a clean 500. A front-controller test pins both `output_buffering` settings.
- **Cache keys.** Symfony Cache validates keys only through `assert()`, which production settings disable. The cache test now checks both behaviors, and the README documents it.

CI now runs the production `php.ini` explicitly, plus one job with the development `php.ini`. Locally, 360 tests pass with and without the production `php.ini`, as do the lowest-version suite and the packaging group.

## Fourteenth milestone: completed — PSR-14 events

Implemented:

- **In the framework.** Events need only the `psr/event-dispatcher` interfaces, so they live in `crystoline/xpsr-framework` (`Crystoline\Xpsr\Events\`) rather than a separate package. An application that never dispatches an event pays only for a few container definitions.
- **Dispatcher.** `EventDispatcher` calls listeners synchronously and returns the event. A stoppable event is checked before every listener, including the first. Listener exceptions reach the caller.
- **Listener provider.** `ListenerProvider` matches events by class, parent class, or interface, and orders listeners by priority, then registration order. Listeners are service IDs, `[service, method]` pairs, or callables. Service listeners are built from the container only when an event reaches them. Malformed listeners and unknown event classes are rejected when registered.
- **Configuration.** `EventServiceRegistry` reads `events.listeners` from `config/events.php`; providers can add listeners with priorities in `boot()`. New projects list the registry in `app.registry` and include an empty `config/events.php`.
- **Console.** `event:list` (with `--event` and `--json`), `make:event`, and `make:listener --event=...`.
- **Docs.** `EVENTS.md`, plus updates to `CONSOLE.md` and `VERSIONING.md`.

Verified on 28 October 2026: 393 tests pass on PHP 8.4 with and without the production `php.ini`, on PHP 8.5 with `pdo_mysql` and the production `php.ini`, and at the lowest allowed dependency versions. The packaging group passes (5 tests). PHPStan and style checks pass.

Not provided: framework-dispatched lifecycle events, and queued or asynchronous listeners.

## Fifteenth milestone: completed — outgoing HTTP client

Implemented:

- **New package.** `crystoline/xpsr-http-client` (`packages/http-client`, `Crystoline\Xpsr\HttpClient\`), backed by Symfony HttpClient. It is not published yet: its mirror repository does not exist, so `scripts/split.sh` leaves it out of `PUBLISHED`.
- **Services.** `HttpClientServiceRegistry` registers a shared PSR-18 `ClientInterface` whose responses come from the application's PSR-17 factories, Symfony's `HttpClientInterface` for streaming and concurrent requests, and one PSR-18 client per entry in `http_client.clients`, as `http_client.<name>`. Named clients share the default client's connections.
- **Options.** `config/http_client.php` sets `timeout`, `max_duration`, `max_redirects`, and default `headers`; named clients add `base_uri`. Unknown options and malformed values, including header values with line breaks, fail with the setting's name.
- **Testing.** `Testing\FakeClient` answers from a queue of responses or closures and records requests. The skeleton's `TestCase` gained `swap()`, which replaces a service, including one from a deferred provider.
- **Dependency floor.** Symfony HttpClient's oldest allowed `symfony/polyfill-php83` printed PHP 8.4 deprecations; the package conflicts with versions below 1.30.

Verified on 28 October 2026:

- **Suite:** 426 tests pass on PHP 8.4 with and without the production `php.ini`, on PHP 8.5, and at the lowest allowed versions.
- **Real requests:** the package's tests send requests to PHP's built-in server. They cover headers, bodies, error statuses, redirects with and without a limit, base URIs, connection failures, timeouts, and request exceptions.
- **Packaging:** 6 tests pass with current and with lowest dependencies, including this package installed alone.

## Sixteenth milestone: completed — faster route building, no route cache

The planned route cache was measured first. Building routes turned out to be slow, but because of a defect rather than missing caching:

- **The defect.** `RouteDefinition::pattern()` parsed a new `RoutePattern` on every call. Each constrained parameter was then re-checked against all 128 ASCII characters. `RouteCollection::all()` sorted with a comparator that parsed two patterns per comparison, and the adapters call `all()`. Building 30 routes took 14.5 ms and 300 routes 276 ms on every request.
- **The fix.** A definition keeps its parsed pattern and replaces it only when a constraint is added. Validated constraints are remembered for the process. The collection orders its routes and indexes their names once, when it freezes.
- **Result.** 30 routes take 0.8 ms and 300 routes 5.7 ms, including the FastRoute adapter, which is 18 to 48 times faster. `named()` and URL generation no longer scan every route. A whole skeleton request (construct, boot, handle) takes 0.7 ms with OPcache.
- **No route cache.** At these numbers a cache would save a few milliseconds only for very large route sets. It would also have to refuse closures and objects as handlers, which the skeleton's own `/hello` route uses. It is left out; `DEPLOYMENT.md` gives the numbers.

Verified on 28 October 2026: 428 tests pass, PHPStan and style checks pass, and new tests cover pattern reuse, ordering, the name index, and repeated rejection of an invalid constraint.

## Seventeenth milestone: completed — database connections and migrations

Implemented:

- **New package.** `crystoline/xpsr-database` (`packages/database`, `Crystoline\Xpsr\Database\`), backed by Doctrine DBAL 4.5. It is not an ORM. Like the HTTP client, it is not published yet.
- **Connections.** `ConnectionManager` builds named connections from `config/database.php`, with DBAL parameters or a URL. A relative SQLite path resolves against the project root and its directory is created. `log_queries` logs through the application's PSR-3 logger. `Doctrine\DBAL\Connection` is the default connection; DBAL connects on the first query.
- **Migrations.** Timestamped files under `migrations` each return a `Migration`. `Migrator` runs pending files as a batch and rolls back by batch or by count. It reports each migration as ran, pending, or "ran, file missing". On PostgreSQL and SQLite each migration runs in a transaction with its bookkeeping; MySQL and MariaDB commit DDL implicitly, which is documented. `Migration::alterTable()` applies a table change through DBAL's comparator.
- **Commands.** `migrate`, `migrate:rollback --steps=N`, `migrate:status --json`, and `make:migration --create|--table`; all take `--connection`. With `APP_ENV=production`, changing the database needs `--force` or a confirmation.
- **Skeleton.** `config/database.php` is replaced by a configuration for this package; the old file was an unused leftover from another framework. The skeleton does not install the package.
- **CI.** A `database` job runs the migration tests against MySQL 8.4, MariaDB 11, and PostgreSQL 17 service containers, and the split waits for it.

Verified on 28 October 2026:

- **Suite:** 458 tests pass on PHP 8.4 with and without the production `php.ini`, on PHP 8.5, and at the lowest allowed versions.
- **Real servers:** the migration tests (12) pass against MySQL 8.4, MariaDB 11, and PostgreSQL 17 in Docker, as the CI job runs them.
- **Generated code:** files from `make:migration` pass `php -l`, and the `--create` and `--table` stubs migrate and roll back as written.
- **Application:** a skeleton test runs `migrate` through the console against an in-memory SQLite connection, as the README describes for tests.
- **Packaging:** 7 tests pass, including this package installed alone.

Not provided: an ORM or query objects, seeders, `migrate:fresh`, and schema dumps.

## Eighteenth milestone: completed — `Crystoline\Xpsr\` namespace and XPSR's own conventions

- **Namespace.** Every class moved from `Crystoline\` to `Crystoline\Xpsr\`, in all seven packages, the skeleton, stubs, and documentation. `CHANGELOG.md` gives a command that upgrades an application; applied to the 0.1.1 skeleton, it produces the same references as the renamed skeleton.
- **Conventions.** Migrations moved from Laravel's `database/migrations` to `migrations/`, with compact UTC timestamps (`20261028120000_name.php`). The `database_path()` helper is removed. `FRAMEWORK_PLAN.md` records both decisions.

Verified on 28 October 2026: 458 tests pass on PHP 8.4 with and without the production `php.ini`, on PHP 8.5, and at the lowest allowed versions, and the packaging group passes.

## Nineteenth milestone: completed — extensible database foundation

The first step of the integration plan in `FRAMEWORK_PLAN.md` section 10, so that ORM adapters and other migration runners can be added without breaking changes:

- **Runner contract.** `MigratorInterface` and `MigratorFactoryInterface`. The migrate commands use only these, and `database.migrations.runner` names the factory; XPSR's `Migrator` and `MigratorFactory` are the default.
- **Contract suite.** `Testing\MigratorContractTestCase`, with four hooks. XPSR's runner passes it.
- **Test transactions.** `Testing\DatabaseTransactions` wraps tests in transactions on any connection and rolls back everything afterwards, including nested and unclosed transactions. A skeleton test uses it with the application `TestCase`.

Verified on 28 October 2026: 469 tests pass, with PHPStan and style checks.

## Twentieth milestone: completed — Doctrine ORM adapter

Built with the choices accepted on 28 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **New package.** `crystoline/xpsr-orm-doctrine` (`packages/orm-doctrine`, `Crystoline\Xpsr\Orm\Doctrine\`), on Doctrine ORM 3.7. Not published yet.
- **Entity manager.** Built on XPSR's DBAL connection (`orm.connection`), with attribute mapping from `app/Entity` (skipped while it does not exist) and PHP 8.4 native lazy objects, so no proxy classes are generated. Columns use snake case unless `orm.naming` is `default`. The metadata cache is the application's PSR-6 cache when installed and `APP_DEBUG` is off, otherwise memory.
- **Generated migrations.** `xpsr-database` gained `SchemaProviderInterface`, `SchemaDiff`, and `make:migration --diff`. The diff compares only tables the expected schema names, so it never drops the migrations table or unmanaged tables, and it refuses while migrations are pending. The adapter provides the schema from the entity mapping.
- **Commands.** Doctrine's `orm:info`, `orm:mapping:describe`, `orm:run-dql`, and cache commands run under `php xpsr`. Doctrine's `orm:validate-schema` counts every unmapped table, including the migrations table, as a difference, so XPSR replaces it with a command that checks the mapping and compares the tables the way `--diff` does. The `orm:schema-tool:*` commands are left out: schema changes go through migrations.
- **Lifecycle and contract.** `ClearEntityManagerMiddleware` clears the entity manager after each request, for worker servers. `xpsr-database` gained `Testing\OrmAdapterContractTestCase` (shared connection and transaction, reads of plain writes, reset between requests), and the adapter passes it.

Verified on 28 October 2026: 487 tests pass with PHPStan and style checks. The database and ORM suites (60 tests) pass against SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17, including generating, running, validating, and rolling back a `--diff` migration on each.

## Twenty-first milestone: completed — CORS and the PSR-20 clock

- **CORS.** `CorsMiddleware` with `CorsOptions` (`config/cors.php`, `CORS_ALLOWED_ORIGINS`): exact origins, subdomain patterns, or any origin; preflight answers with 204 before the application runs; the response headers on every answer to an allowed origin, including errors, which the middleware renders through the exception handler because the kernel renders exceptions outside the global middleware; `Vary` handling; credentials echo the origin instead of `*`. New projects list it first in `app.middleware`, inactive until an origin is configured.
- **Clock.** The framework requires `psr/clock` and `symfony/clock` and registers `Psr\Clock\ClockInterface` (and Symfony's clock interface) as a `NativeClock` in `app.timezone`. The skeleton `TestCase` gains `freezeTime()`, which swaps in a `MockClock`.

Verified on 28 October 2026: 510 tests pass with PHPStan and style checks, including CORS through the skeleton's full HTTP kernel (preflight, success, a 404 with headers, other origins, and the default off state).

## Boundaries and remaining work

The first twenty-one milestones are working slices, not completion of all roadmap phases. Phases 0 to 5 are complete, including publishing.

- **Publishing.** Live since 28 October 2026. CI publishes `master` to the five mirror repositories under `crystoline`, and Packagist serves all five packages from them. The first release is `0.1.0`. The old prototype's `v1.0.0` is removed from Packagist; its git tag remains in `crystoline/xpsr`, where it no longer affects installs.
- **Setup presets.** The `setup` presets know the skeleton's route file names; this belongs in a future installer package.
- **Containers.** Alternative containers need an adapter for XPSR's registration/deferred-group contract; PSR-11 alone only standardizes consumption.
- **Deferred services.** Deferred service claims cannot overlap. Rebinding does not rebuild existing consumers automatically.
- **Route grammar.** It intentionally supports static paths, whole-segment placeholders, and simple ASCII constraints. Optional segments, host patterns, arbitrary regex constraints, route caches, and signed/absolute URLs are not supported.
- **Long-running servers.** Global helpers use the static application instance. Isolation between application instances, and worker-server readiness, are not established.
- **HTTP gaps.**
  - Multipart bodies are parsed only for POST, by PHP.
  - The emitter does not compress, add `Content-Length`, or serve ranges.
  - Only Nyholm PSR-7 is tested.
  - The request host comes from the client's `Host` header; build absolute URLs from `APP_URL`.
- **Dependencies.** The framework's remaining third-party requirements are Symfony Console, Process, and Finder; Illuminate Config; phpdotenv; and Nyholm's PSR-7 server helper. Finder only lists configuration files, and Illuminate Config pulls in parts of Illuminate Support, so both are candidates for small in-house replacements.
- **Not yet available:** file storage (roadmap phase 6; `FRAMEWORK_PLAN.md` section 10). Configuration and route caches (`config:cache`, `route:cache`) are not provided; route files and configuration load on each request, which the sixteenth milestone measured as cheap.
- **Windows.** Native Windows execution of the development server and generators is not verified.

## Thirtieth milestone: completed — scheduled tasks

Built with the choices accepted on 29 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Package.** `crystoline/xpsr-scheduler` (`Crystoline\Xpsr\Scheduler\`, not published yet), on `dragonmantank/cron-expression` and `symfony/lock`. `SchedulerServiceRegistry` loads `routes/schedule.php` (a missing default file means no tasks; a missing configured one is an error) into the shared `Schedule`.
- **Tasks.** `Schedule::command()`, `call()`, and `job()` return a `Task` with frequency helpers, day-of-week limits (refused next to a day of the month), `timezone()`, `name()`, `when()`, `skip()`, and `withoutOverlapping()`. `TaskRunner` runs commands through `ConsoleKernel` with their output captured, calls callbacks with injected services, and queues jobs, returning a `TaskResult` instead of throwing.
- **Commands.** `schedule:run` (failures to stderr and the log, exit code 1 when any failed), `schedule:list`, and `schedule:work` (a new `schedule:run` process each minute, `--time-limit`, stopping on SIGTERM and SIGINT).
- **Daylight saving.** As cron-expression decides: a skipped local time runs an hour later, a repeated one runs twice; the README says so.

Verified on 29 October 2026: the package's 52 tests pass with PHPStan and style checks, including schedule:work starting real processes on a mock clock.

## Twenty-ninth milestone: completed — mail

Built with the choices accepted on 29 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Package.** `crystoline/xpsr-mail` (`Crystoline\Xpsr\Mail\`, not published yet), on Symfony Mailer and Mime 7.4 or 8. `MailServiceRegistry` builds the transport from `mail.dsn` with Symfony's factories and XPSR's `log://` and `array://`; an unknown scheme names `mail.dsn` and the bridge to install.
- **Mailer.** `send()` fills in `mail.from`; `compose()` renders the HTML body through the view layer, with a text version (styles and scripts dropped, links as `text (url)`) or a text view; `queue()` dispatches the finished email as a `SendMail` job when `crystoline/xpsr-queue` is registered, and otherwise says what to add.
- **Tooling.** `mail:test <to>`; `Transport\ArrayTransport` with `emails()`, `messages()`, and `clear()` for tests; `Transport\LogTransport` logs recipients, sender, subject, and text.

Verified on 29 October 2026: the package's 13 tests pass with PHPStan and style checks, including an email queued on a serializing transport and sent by `queue:work`.

## Twenty-eighth milestone: completed — queues

Built with the choices accepted on 29 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Package.** `crystoline/xpsr-queue` (`Crystoline\Xpsr\Queue\`, not published yet), on Symfony Messenger 7.4 or 8. `QueueInterface` and `Queue` send `JobInterface` jobs through a Messenger bus with one handler, `JobRunner`, which calls `handle()` with services from the container. On `sync://`, a job's own exception reaches the caller.
- **Transports.** `Messenger\Transports` builds them from DSNs: sync, in-memory, `database://` (Messenger's Doctrine transport on an XPSR connection, with `auto_setup` off), and the Redis, AMQP, and SQS bridges when installed.
- **Failures.** Messenger's retry listener with a multiplier strategy per transport, then `FailedJobListener` and `FailedJobStore` (the `failed_jobs` table); `queue:failed`, `queue:retry`, `queue:forget`.
- **Workers.** `queue:work` with `--limit`, `--time-limit`, `--memory-limit`, `--sleep`, and `--stop-when-empty`, stopping on SIGTERM and SIGINT after the current job; `queue:restart` through the cache.
- **Tooling.** `make:job`, `make:queue-table` (on the database package's `TableMigrationCommand`, which gained `stubPath()` and `replacements()` hooks), and `Testing\FakeQueue`.

Verified on 29 October 2026: the package's 18 tests pass with PHPStan and style checks, on SQLite, MySQL, MariaDB, and PostgreSQL.

## Twenty-seventh milestone: completed — several kinds of user

Accepted on 29 October 2026, for admins (or others) in their own table:

- **Providers per guard.** `auth.providers` names providers; each guard has a `provider` and optionally its own `login_path` and `home_path`. `auth.users` stays the short form for one provider.
- **Sessions.** Each session guard signs in under its own key; `attempt()`, `login()`, `logout()`, and `intended()` take a guard. Signing out of one guard keeps the others and moves the session to a new ID; signing out of the last ends it. `Guest::guard()` joins `Authenticate::guard()`.
- **Tokens.** JWTs carry a `guard` claim and database tokens a `guard` column; a guard accepts only its own, so equal IDs in two tables never mix. `auth:jwt` and `auth:token` take `--guard`.
- **Policies.** The gate refuses a user whose class a check's user parameter does not accept, instead of a `TypeError`.

Verified on 29 October 2026: 760 tests pass with PHPStan and style checks.

## Twenty-sixth milestone: completed — Eloquent adapter

Built with the choice accepted on 29 October 2026: XPSR's migrations, not Eloquent's.

- **Package.** `crystoline/xpsr-orm-eloquent` (`Crystoline\Xpsr\Orm\Eloquent\`, not published yet). `ConnectionResolver` builds an Eloquent connection per XPSR connection on the PDO DBAL opened, choosing MySQL, MariaDB, PostgreSQL, SQLite, or SQL Server from DBAL's platform. The connection classes route `beginTransaction()`, `commit()`, `rollBack()`, and `transaction()` through DBAL. `EloquentServiceRegistry` sets the models' resolver and an event dispatcher, and registers `make:model`.
- **Auth.** `EloquentUserProvider` (`auth.users` driver `eloquent`) and the `Authenticatable` trait.
- **Framework.** `StubGenerator::withStubDirectory()`, for generators whose stubs live in another package.

Verified on 29 October 2026: 747 tests pass with PHPStan and style checks, including the ORM adapter contract, transactions nested both ways, models on a second connection, model events, and users, on all four databases.

## Twenty-fifth milestone: completed — authentication and authorization

Built with the choices accepted on 29 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Framework.** `Crystoline\Xpsr\Auth\`: `UserInterface` and `UserProviderInterface`; `PasswordHasher` (Argon2id or bcrypt, `needsRehash()`, a constant-time miss for unknown logins); `AuthManager` (`verify()`, `attempt()`, `login()`, `logout()`, `intended()`, guards by driver, providers by driver, lazily resolved for guests); `SessionGuard`, which keeps the user's ID and a password-hash fingerprint in the session; `Identity` with scopes; `Http\Authenticate` (required or optional, per guard), `Http\Guest`, and `Http\RequireScopes`; `UnauthenticatedException` (401) and `InsufficientScopeException` (403, RFC 6750 challenges); `Access\Gate` with policies, `before()`, guest rules, and defined abilities, and `AccessDeniedException` (403); `InMemoryUserProvider` and `GenericUser`. Twig gets `auth_user()` and `can()`.
- **JWT.** New package `crystoline/xpsr-auth-jwt` (`Crystoline\Xpsr\Auth\Jwt\`, not published yet) on `lcobucci/jwt` 5.6: `JwtGuard` checks the signature, issuer, audience, and time window with leeway, and looks the user up on each request; `JwtIssuer`; HS256 to HS512, RS256, and ES256; `auth:jwt-secret` and `auth:jwt`.
- **Database.** `DatabaseUserProvider` (`auth.users` driver `database`); `TokenRepository` and `TokenGuard` (driver `token`) with SHA-256-hashed tokens, scopes, expiry, and last use; `make:token-table`, `auth:token`, and `auth:token-revoke`. `make:session-table` and `make:token-table` share `TableMigrationCommand`.
- **Doctrine.** `DoctrineUserProvider` (driver `doctrine`) and `PasswordUpgradableInterface`.
- **Skeleton.** `config/auth.php` with the API driver `jwt` by default; `AuthServiceRegistry` in `app.registry`; `Authenticate::optional()` in the web group; `TestCase::actingAs()`.

Verified on 29 October 2026: 738 tests pass with PHPStan and style checks, including browser sign-in, the page to return to, sign-out, a JWT API with scopes, and policies in a view through the skeleton's full HTTP kernel, and the token and user tables on all four databases.

## Twenty-fourth milestone: completed — sessions, cookies, and CSRF

Built with the choices accepted on 28 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Sessions.** `Crystoline\Xpsr\Session\`: `Session` (values as JSON data, flash values for the next request, `regenerate()`, `invalidate()`, the CSRF token), `SessionMiddleware`, `SessionManager`, and `SessionStoreInterface` with `FileSessionStore` and `ArraySessionStore`. A session is stored, and its cookie sent, only once it holds something. Unknown or malformed IDs from the browser get a new random ID. `SessionServiceRegistry` reads `config/session.php` and registers `session:prune`; the skeleton lists it and replaces its old example `SessionMiddleware` and `SessionServiceRegistry`, which used `session_start()`.
- **Stores in packages.** `crystoline/xpsr-cache` provides `CacheSessionStore`; `crystoline/xpsr-database` provides `DatabaseSessionStore` and `make:session-table`. Every store passes `Testing\SessionStoreContractTestCase`; the database store also on MySQL 8.4, MariaDB 11, and PostgreSQL 17.
- **CSRF.** `CsrfMiddleware` checks the `_token` field or `X-CSRF-Token` header on every method except GET, HEAD, OPTIONS, and TRACE, and throws `InvalidCsrfTokenException` (403, with its message in every environment). Twig gets `csrf_field()`, `csrf_token()`, and `session()`. The skeleton applies both middleware to a group in `routes/web.php`.
- **Cookies and redirects.** `Http\Cookie` builds `Set-Cookie` headers with HttpOnly, Secure, and SameSite=Lax by default. `ResponseBuilder::redirect()` answers 303 by default. `ViewHandler::addFunction()` adds Twig functions.
- **Tests.** The skeleton `TestCase` keeps cookies between requests in a test and adds `postForm()`, `csrfToken()`, `withSession()`, `session()`, and `send()`. `phpunit.xml` sets `SESSION_STORE=array`.

Verified on 28 October 2026: 673 tests pass with PHPStan and style checks, including a form, a flash message after a redirect, login and logout through the skeleton's full HTTP kernel, and the database store on all four databases.

## Twenty-third milestone: completed — request validation

Built with the choices accepted on 28 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Framework.** `Validation\ValidatorInterface`; `ValidationException` (422) implementing the new `Http\Exception\PublicErrorInterface`, which the exception handler renders with its message and details in every environment and without a debug page; and `InputMapper`, which maps the body, query string, or route parameters onto an input class's constructor. It applies `#[Field]` and `#[Input(naming: 'snake')]`, runs the filter attributes (class defaults, then the field's own, with `#[Trim(false)]`-style opt-out), converts to the parameter types (scalars from strings, dates, backed enums, nested input classes), and collects every problem as a field error.
- **Package.** `crystoline/xpsr-validation` (`Crystoline\Xpsr\Validation\Symfony\`), published to its mirror repository: `SymfonyValidator` runs `#[Assert\…]` rules after mapping and reports violations under the client's field names, nested paths included; `validateArray()` checks arrays against constraints. It also requires `symfony/property-access`, which comparisons between fields need, and registers `make:input`.
- **Blank fields.** Text that filters empty to `null` in a field that does not allow `null` is reported as "This value should not be blank.", not as a null error, so a required field sent as spaces reads naturally.

Verified on 28 October 2026: 570 tests pass with PHPStan and style checks, including a generated input class validating a request through the full HTTP kernel (201 for valid input, 422 with field errors for invalid input).

## Twenty-second milestone: completed — Monolog logging and rate limiting

Built with the choices accepted on 28 October 2026 (`FRAMEWORK_PLAN.md` section 9):

- **Monolog.** New package `crystoline/xpsr-log-monolog` (`Crystoline\Xpsr\Log\Monolog\`), published to its mirror repository. `MonologServiceRegistry` rebinds `Psr\Log\LoggerInterface`, so the exception handler's reports go through Monolog. Channels `stderr`, `file`, `daily`, and `syslog`; `line` or `json` format (JSON by default in production); `LOG_LEVEL`; placeholders interpolated with `PsrLogMessageProcessor`; stack traces for logged exceptions. `RequestIdMiddleware` keeps a safe incoming `X-Request-Id` or generates one, and `RequestIdProcessor` adds it to every line, including the error report for an exception that escapes the middleware.
- **Rate limiting.** In `crystoline/xpsr-cache`: a `RateLimitMiddleware` service per limit in `rate_limit.limits`, named with `RateLimit::named()`, on `symfony/rate-limiter`'s sliding window with the application cache as storage. `IpKeyResolver` counts by client address through the new framework class `Http\ClientIp`, which reads `X-Forwarded-For` from the right through the trusted proxies; `ClientKeyResolverInterface` allows other identities. Over the limit, `TooManyRequestsHttpException` renders 429 with `Retry-After` and the rate-limit headers.

Verified on 28 October 2026: 549 tests pass with PHPStan and style checks, including both packages in the skeleton's full HTTP kernel (a limit through `app.middleware` reaching 429, and a 500 logged as JSON with its request ID).

## Release 0.3.0 (28 October 2026)

Tagged `v0.3.0` in all ten repositories. Packagist serves 0.3.0 for every package, including the new `crystoline/xpsr-log-monolog` and `crystoline/xpsr-validation`. Checked from Packagist with the production `php.ini`:

- `composer create-project crystoline/xpsr` installs framework and FastRoute 0.3.0; `about` reports 0.3.0; the application's tests pass and `/hello` answers.
- `setup --router=sunrise --install-dependencies` installs the Sunrise adapter `^0.3`.
- Every optional package installs at 0.3.0. The Doctrine path (entity, `make:migration --diff`, `migrate`, `orm:validate-schema`) works end to end on SQLite.
- With the validation and Monolog providers: an input class from `make:input` accepts trimmed input with 201 and rejects a blank field with 422 and its field error; responses carry `X-Request-Id`; `LOG_FORMAT=json` writes JSON lines to stderr.
- `UPGRADE.md` was rehearsed on an installed 0.2.0 application, which then runs 0.3.0 and passes its tests.

## Release 0.2.0 (28 October 2026)

Tagged `v0.2.0` in all eight repositories. Packagist serves 0.2.0 for every package, including the new `crystoline/xpsr-http-client`, `crystoline/xpsr-database`, and `crystoline/xpsr-orm-doctrine`. Checked from Packagist with the production `php.ini`:

- `composer create-project crystoline/xpsr` installs framework and FastRoute 0.2.0; `about` reports 0.2.0; the application's tests pass and `/hello` answers.
- `setup --router=sunrise --install-dependencies` installs the Sunrise adapter `^0.2`.
- The cache, HTTP client, database, and Doctrine ORM packages install at 0.2.0. With the Doctrine adapter, an entity in `app/Entity`, `make:migration --diff`, `migrate`, and `orm:validate-schema` work end to end on SQLite.
- `UPGRADE.md` was rehearsed on an installed 0.1.1 application, which then runs 0.2.0 and passes its tests.

## Next implementation slice

Follow the ordered component plan in `FRAMEWORK_PLAN.md` section 10, next with Monolog logging and rate limiting; settle each component's open decisions with the user before starting it. A new package needs its own mirror repository, the `SPLIT_TOKEN` scope, and a Packagist entry before `scripts/split.sh` can publish it; see "Adding a package" in `PACKAGES.md`.
