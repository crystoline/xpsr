# Versioning and upgrades

XPSR follows [Semantic Versioning](https://semver.org/). This page defines what the version numbers promise.

## One version for every package

`crystoline/xpsr` (the skeleton) and every `crystoline/xpsr-*` package (the framework, the router adapters, and the optional packages such as `xpsr-cache`) are released together with the same version number. Each package requires the matching framework range: adapter 0.2.x works with framework 0.2.x. A release that changes only one package still tags all of them, so you never need a compatibility table.

## Public API

The backward-compatibility promise covers:

- **Classes, interfaces, and their public methods** in `crystoline/*` packages, unless marked `@internal`. This includes `Crystoline\Xpsr\Contracts\*`, the container, `ServiceRegistry`, `Application`, `Configuration`, the HTTP kernel, middleware, response builder and emitter, the HTTP exceptions (`Crystoline\Xpsr\Http\Exception\*`), `IpRange`, routing definitions (`RouteCollection`, `RouteDefinition`, `RouteMatch`, `AbstractRouter`), the URL generator, the event dispatcher and listener provider (`Crystoline\Xpsr\Events\*`), validation (`Crystoline\Xpsr\Validation\*`: the validator interface, the exception and its error body, the attributes and filters, and the input mapping rules), the exception handler, the logger, `Crystoline\Xpsr\Testing\RouterContractTestCase`, and `Crystoline\Xpsr\Testing\ViewHandlerContractTestCase`.
- **Route files**: a callable receiving `RouteCollection`, and the portable route grammar in [ROUTING.md](ROUTING.md).
- **Configuration keys and environment variables** documented in the docs, such as `app.registry`, `app.middleware`, `app.timezone`, `events.listeners`, `cors.allowed_origins`, `rate_limit.limits`, `logging.channel`, `routing.adapter`, `CORS_ALLOWED_ORIGINS`, `LOG_CHANNEL`, `LOG_FORMAT`, `ROUTER_ADAPTER`, `LOG_LEVEL`, and `TRUSTED_PROXIES`.
- **Documented HTTP behavior**: status codes for 404, 405, 400, and 413, the `{"message": ...}` error body, HEAD/OPTIONS handling, and body parsing.
- **Console commands**: names, documented options, and exit codes.

Not covered:

- Anything marked `@internal`: the setup and generator internals, the built-in command classes (their command-line behavior is covered, their PHP API is not), the provider manager, the middleware pipeline, the route dispatcher, the handler resolver, and route pattern parsing.
- Files under `Console/Resources/`, including the stubs. Generated code may change in any release, but code you already generated is yours and never changes.
- Exception messages and console output wording, except the exit codes.
- Tests and test fixtures inside the packages.
- The skeleton's example code (`HomeController`, `GreetCommand`, the `/hello` route). You own and edit these after creating a project.

## Before 1.0

XPSR is in its 0.x series. Composer reads `^0.5` as "0.5.0 or newer, below 0.6.0", so:

- **Patch releases (0.5.1, 0.5.2, …)** contain fixes only. They never break the public API.
- **Minor releases (0.6.0, 0.7.0, …)** may break the public API. Every break is listed under **Breaking** in `CHANGELOG.md`, with migration steps in [UPGRADE.md](../UPGRADE.md).

Require `^0.5` and upgrade between minor versions deliberately.

## From 1.0

- Patch and minor releases never break the public API. Minor releases add features.
- Features are deprecated in a minor release, documented in `CHANGELOG.md`, marked `@deprecated`, and trigger an `E_USER_DEPRECATED` notice where practical. They are removed only in the next major release.
- Major releases may remove deprecated features and make other breaking changes, each listed in `UPGRADE.md`.

## PHP and dependency versions

- Each release supports the PHP versions tested in CI (currently 8.4 and 8.5). Dropping a PHP version is a breaking change: it needs a minor release before 1.0 and a major release after.
- Minimum dependency versions must be free of deprecation notices on every supported PHP version. CI runs the whole test suite against the lowest allowed versions with deprecations treated as failures. Raising a minimum version to fix a defect is allowed in any release.
- Supporting a new major version of a dependency, such as `psr/http-message` 2, is not a breaking change on its own.

## Making a release

1. Move the **Unreleased** entries in `CHANGELOG.md` under the new version, update `UPGRADE.md` for breaking changes, and commit.
2. Ensure CI passes, including the packaging and lowest-dependency jobs.
3. For a new minor line, such as 0.6, update the branch aliases and the packages' requirements on each other (for example `^0.6@dev`).
4. Tag `vX.Y.Z` in the monorepo and push the tag. CI's `split` job tags every package repository, and exports the skeleton with constraints on the release range ([PACKAGES.md](PACKAGES.md#publishing)).

The first tagged release is `0.1.0`. Packagist already lists an unrelated prototype as `crystoline/xpsr` `v1.0.0`; remove that version before tagging `0.1.0`, otherwise `composer create-project crystoline/xpsr` keeps selecting it.
