# XPSR packages

XPSR is a set of Composer packages with one version number. An application installs the framework, one router adapter, and the optional packages it wants, and nothing else comes along: the framework does not depend on the application skeleton, and no package depends on one it does not use.

## What an application starts with

`composer create-project crystoline/xpsr` (or `xpsr new`) installs:

| Package | Why |
|---|---|
| `crystoline/xpsr-framework` | The framework: lifecycle, container, HTTP kernel, routing, views, sessions, authentication, console. |
| `crystoline/xpsr-router-fastroute` | The default router adapter. `php xpsr setup --router=sunrise` swaps it for the Sunrise one. |
| `nyholm/psr7` | The PSR-7 and PSR-17 implementation `config/factory.php` uses. |
| `twig/twig` | Views, for the `web` preset. An API-only application can remove it. |
| `filp/whoops` (development) | Detailed error pages with `APP_DEBUG=true`. |

Everything else is optional: `composer require` the package, add its registry to `app.registry` where its README says so, and it is in. Each README has the setup, the configuration, and what the package does not do.

## Packages and dependencies

| Package | Namespace | Depends on |
|---|---|---|
| `crystoline/xpsr-framework` | `Crystoline\Xpsr\` | PSR interfaces, Symfony Console and Process, phpdotenv, Nyholm PSR-7 server, and any PSR-17 implementation (`psr/http-factory-implementation`). Accepts `psr/http-message` 1.1 or 2.0. Suggests Twig (views) and Whoops (debug pages), but does not require them. |
| `crystoline/xpsr-router-fastroute` | `Crystoline\Xpsr\Router\FastRoute\` | framework, `nikic/fast-route` ^1.3 |
| `crystoline/xpsr-router-sunrise` | `Crystoline\Xpsr\Router\Sunrise\` | framework, `sunrise/http-router` ^3.4, `psr/http-message` ^1.1 or ^2.0, `psr/http-server-handler`, `psr/http-server-middleware` |
| `crystoline/xpsr-installer` | `Crystoline\Xpsr\Installer\` | `symfony/console` and `symfony/process` 7.4 or 8, and nothing of XPSR: it is installed globally and runs Composer and a project's own commands. Provides the `xpsr` binary. |
| `crystoline/xpsr-cache` | `Crystoline\Xpsr\Cache\` | framework, `symfony/cache` ^7.4, `symfony/rate-limiter` ^7.4, `psr/cache` 2 or 3, `psr/simple-cache` 1 to 3 |
| `crystoline/xpsr-database` | `Crystoline\Xpsr\Database\` | framework, `doctrine/dbal` ^4.5. |
| `crystoline/xpsr-orm-doctrine` | `Crystoline\Xpsr\Orm\Doctrine\` | framework, database, `doctrine/orm` ^3.7, `doctrine/persistence` ^4.1, `symfony/cache` ^7.4. |
| `crystoline/xpsr-orm-eloquent` | `Crystoline\Xpsr\Orm\Eloquent\` | framework, database, `illuminate/database` ^12.0, `illuminate/events` ^12.0. |
| `crystoline/xpsr-orm-cycle` | `Crystoline\Xpsr\Orm\Cycle\` | framework, database, `cycle/orm` ^2.18, `cycle/annotated` ^4.6, `cycle/schema-builder` ^2.12, `cycle/entity-behavior` ^1.7, `spiral/tokenizer` ^3.17. |
| `crystoline/xpsr-validation` | `Crystoline\Xpsr\Validation\Symfony\` | framework, `symfony/validator` ^7.4, `symfony/property-access` ^7.4. The contract, attributes, and mapping it uses are in the framework (`Crystoline\Xpsr\Validation\`). |
| `crystoline/xpsr-api` | `Crystoline\Xpsr\Api\` | framework, `psr/http-message` 1.1 or 2.0; `crystoline/xpsr-database`, `crystoline/xpsr-orm-doctrine`, `crystoline/xpsr-orm-eloquent`, and `crystoline/xpsr-orm-cycle` for paging their queries. |
| `crystoline/xpsr-http-client` | `Crystoline\Xpsr\HttpClient\` | framework, `symfony/http-client` ^7.4, `psr/http-client` ^1.0.3. |
| `crystoline/xpsr-log-monolog` | `Crystoline\Xpsr\Log\Monolog\` | framework, `monolog/monolog` ^3.9. |
| `crystoline/xpsr-auth-jwt` | `Crystoline\Xpsr\Auth\Jwt\` | framework, `lcobucci/jwt` ^5.6 (`ext-openssl`, `ext-sodium`). The contracts, session sign-in, and policies are in the framework (`Crystoline\Xpsr\Auth\`); the token driver and database users are in `crystoline/xpsr-database`. |
| `crystoline/xpsr-auth-oauth2-server` | `Crystoline\Xpsr\Auth\OAuth2\` | framework, database, `league/oauth2-server` 8.5.5 or 9.4, `defuse/php-encryption` 2.4. |
| `crystoline/xpsr-queue` | `Crystoline\Xpsr\Queue\` | framework, database, `symfony/messenger`, `symfony/doctrine-messenger`, and `symfony/event-dispatcher` 7.4 or 8; Redis, AMQP, and SQS through their Messenger bridges. |
| `crystoline/xpsr-mail` | `Crystoline\Xpsr\Mail\` | framework, `symfony/mailer` and `symfony/mime` 7.4 or 8; `crystoline/xpsr-queue` for queued email; providers through Symfony's mailer bridges. |
| `crystoline/xpsr-lock` | `Crystoline\Xpsr\Lock\` | framework, `symfony/lock` and `symfony/clock` 7.4 or 8; `symfony/cache` with phpredis or `predis/predis` for Redis and Redlock; `crystoline/xpsr-database` for the database store. |
| `crystoline/xpsr-notifications` | `Crystoline\Xpsr\Notifications\` | framework, `symfony/notifier` 7.4 or 8; `crystoline/xpsr-mail` for the mail channel, `crystoline/xpsr-database` for the database and webpush channels, `minishlink/web-push` ^11 for web push, `crystoline/xpsr-queue` for queued notifications; SMS providers through Symfony's notifier bridges. |
| `crystoline/xpsr-scheduler` | `Crystoline\Xpsr\Scheduler\` | framework, `dragonmantank/cron-expression` ^3.4, `symfony/lock` 7.4 or 8; `crystoline/xpsr-queue` for scheduled jobs. |
| `crystoline/xpsr-storage` | `Crystoline\Xpsr\Storage\` | framework, `league/flysystem`, `league/flysystem-local`, and `league/flysystem-memory` ^3.29; `league/flysystem-async-aws-s3` for the s3 driver. |
| `crystoline/xpsr-webhooks` | `Crystoline\Xpsr\Webhooks\` | framework, database, queue, and http-client; `symfony/console` 7.4 or 8. Endpoint secrets use the framework's `Encrypter` (`APP_KEY`). |
| `crystoline/xpsr-audit` | `Crystoline\Xpsr\Audit\` | framework and database; `symfony/console` 7.4 or 8. The Eloquent, Doctrine, Cycle, and Monolog packages are detected when installed. |
| `crystoline/xpsr-telemetry` | `Crystoline\Xpsr\Telemetry\` | framework and http-client; `open-telemetry/sdk` ^1.15, `open-telemetry/exporter-otlp` ^1.4 (which bring `google/protobuf`). The database, queue, scheduler, and Monolog packages are detected when installed. |
| `crystoline/xpsr-health` | `Crystoline\Xpsr\Health\` | framework; `symfony/console` 7.4 or 8. The database, cache, queue, scheduler, lock, and HTTP client packages are detected when installed. |
| `crystoline/xpsr-translation` | `Crystoline\Xpsr\Translation\` | framework, `symfony/translation` and `symfony/config` 7.4 or 8; `ext-intl` for the ICU format. The validation package is detected when installed. |
| `crystoline/xpsr-broadcast` | `Crystoline\Xpsr\Broadcast\` | framework, http-client, `lcobucci/jwt` 5.6. The queue and database packages are detected when installed. |
| `crystoline/xpsr-view-plates` | `Crystoline\Xpsr\View\Plates\` | framework, `league/plates` ^3.6. |
| `crystoline/xpsr-view-latte` | `Crystoline\Xpsr\View\Latte\` | framework, `latte/latte` ^3.1.2. |
| `crystoline/xpsr-view-blade` | `Crystoline\Xpsr\View\Blade\` | framework, `illuminate/view`, `illuminate/container`, `illuminate/events`, `illuminate/filesystem`, and `illuminate/support` ^12.0. |
| `crystoline/xpsr` | `App\` | framework, FastRoute adapter, `nyholm/psr7`, `twig/twig`; development: `filp/whoops`, `phpunit/phpunit` |

How the packages relate:

- The framework never depends on the skeleton or on an adapter. It knows the official adapters by name, `fastroute` and `sunrise`, and if the selected one is not installed, it says which package to install.
- Adapters depend on the framework, never on each other.
- An optional package depends on the framework and on the packages it needs, as the table says, and detects the others at run time where it can use them: the health checks find the queue, the audit log finds the ORM, and so on.

## Versions

Every package is released with the same version number, and each requires the matching framework range: adapter 0.7.x works with framework 0.7.x. Require `^0.7` on every `crystoline/xpsr-*` package and upgrade them together. [VERSIONING.md](VERSIONING.md) says what each kind of release promises, and [UPGRADE.md](../UPGRADE.md) has the steps between minor versions.

A development install (`xpsr new --dev`, or `composer create-project crystoline/xpsr my-app dev-master`) follows the `master` branches, where unreleased changes are, and which Composer sees as the development version of the current line (`0.7.x-dev`).

## Repositories

Development happens in [crystoline/xpsr](https://github.com/crystoline/xpsr), one repository with the skeleton at its root and every package under `packages/`. Each package also has a repository of its own, `crystoline/xpsr-<name>` (the skeleton's is `crystoline/xpsr-skeleton`), which Packagist reads; those are read-only mirrors that the main repository's CI updates. Report issues and send pull requests to `crystoline/xpsr`: [CONTRIBUTING.md](../CONTRIBUTING.md) has the setup, the checks, and how a release is made.
