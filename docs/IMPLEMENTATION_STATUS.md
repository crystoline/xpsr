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

- Replaced the untested `ResponseWriter` with `Crystoline\Http\ResponseEmitter`:
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
  - `crystoline/xpsr-router-fastroute` and `crystoline/xpsr-router-sunrise`: the adapters, now `Crystoline\Router\FastRoute\FastRouteRouter` and `Crystoline\Router\Sunrise\SunriseRouter`.
  - The skeleton requires the framework and FastRoute adapter through a path repository. Sunrise is a suggestion and a development requirement.
- The framework names the official adapters only as strings, so it has no dependency on either. A missing adapter fails with the setup command to run. `setup` now installs the adapter packages, using `--update-with-dependencies` so Composer can move `psr/http-message` to the major version Sunrise needs.
- The framework accepts `psr/http-message` 1.1 or 2.0. Only the Sunrise adapter pins 1.x.
- The shared routing suite is now `Crystoline\Testing\RouterContractTestCase`, shipped with the framework. Each adapter package runs it from its own tests; third-party adapters can do the same.
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
- **Minimum dependency versions.** Running the whole suite at the lowest allowed versions showed that `vlucas/phpdotenv` 5.4, `nyholm/psr7` 1.8.0, `filp/whoops` 2.14, and `symfony/var-dumper` 6.4 print PHP 8.4 deprecation notices. Those notices corrupted HTTP responses and console JSON in 12 tests. The minimums are now phpdotenv `^5.6.1`, Nyholm PSR-7 `^1.8.2`, and Whoops `^2.16`, with a framework `conflict` on `symfony/var-dumper` below 7.4. Locked versions did not change.
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
- A `split` CI job runs the script after every other job passes. It runs only in `xpsr-php/xpsr`, on `master` and `v*` tags, with a `SPLIT_TOKEN` secret.
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

- **XPSR HTTP exceptions.** `Crystoline\Http\Exception`:
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

## Boundaries and remaining work

The first twelve milestones are working slices, not completion of all roadmap phases. Phase 5 is complete except publishing, which needs the repository owner's accounts and decisions.

- **Publishing.** The split job and release flow are ready and rehearsed (see the tenth milestone). What remains needs the owner's accounts: the `xpsr-php` organization and repositories, the `SPLIT_TOKEN` secret, removal of the old `crystoline/xpsr` `v1.0.0`, Packagist registration, and the `v0.1.0` tag. See "First publication" in `PACKAGES.md`.
- **Setup presets.** The `setup` presets know the skeleton's route file names; this belongs in a future installer package.
- **Containers.** Alternative containers need an adapter for XPSR's registration/deferred-group contract; PSR-11 alone only standardizes consumption.
- **Deferred services.** Deferred service claims cannot overlap. Rebinding does not rebuild existing consumers automatically.
- **Route grammar.** It intentionally supports static paths, whole-segment placeholders, and simple ASCII constraints. Optional segments, host patterns, arbitrary regex constraints, route caches, and signed/absolute URLs are not supported.
- **Long-running servers.** Global helpers use the static application instance. Isolation between application instances, and worker-server readiness, are not established.
- **HTTP gaps.**
  - Sessions and CSRF remain web integration work.
  - Multipart bodies are parsed only for POST, by PHP.
  - The emitter does not compress, add `Content-Length`, or serve ranges.
  - Only Nyholm PSR-7 is tested.
  - The request host comes from the client's `Host` header; build absolute URLs from `APP_URL`.
- **Dependencies.** The framework's remaining third-party requirements are Symfony Console, Process, and Finder; Illuminate Config; phpdotenv; and Nyholm's PSR-7 server helper. Finder only lists configuration files, and Illuminate Config pulls in parts of Illuminate Support, so both are candidates for small in-house replacements.
- **Not yet available:** cache operations, database integration, authentication, queues, and scheduling (roadmap phase 6).
- **Windows.** Native Windows execution of the development server and generators is not verified.

## Next implementation slice

Publishing is ready to run once the GitHub organization, the `SPLIT_TOKEN` secret, and Packagist access exist; follow "First publication" in `PACKAGES.md`.

Without accounts, start roadmap phase 6 with a PSR-16 cache integration and a `cache:clear` command. It should be an optional package, so applications that do not cache stay unaffected.
