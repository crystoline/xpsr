# XPSR

A PHP framework built on PSR standards, with the parts you choose.

XPSR gives an application a PSR-15 HTTP kernel and middleware, a PSR-11 container with providers, routing that is portable across FastRoute and Sunrise, views in Twig, Plates, Latte, or Blade, sessions with CSRF protection, authentication with guards and policies, PSR-14 events, and an `xpsr` console with generators. Around it are optional packages for the database and three ORMs, queues, mail, caching and rate limiting, locks, notifications, scheduled tasks, file storage, validation, logging, an HTTP client, webhooks, an audit log, tracing, health checks, translations, broadcasting, and an OAuth2 server. Every package shares one version number, and the framework itself depends on Symfony Console and Process, phpdotenv, and Nyholm's PSR-7, nothing else.

## Get started

```sh
composer global require crystoline/xpsr-installer
xpsr new my-app
cd my-app
php xpsr serve                # http://127.0.0.1:3001
```

Or with Composer alone: `composer create-project crystoline/xpsr my-app`, then `php xpsr setup` in it. PHP 8.4 or newer and Composer 2 are required.

[Getting started](docs/GETTING_STARTED.md) builds a small API with tests. [Deployment](docs/DEPLOYMENT.md) takes it to production. [Coming from Laravel](docs/FROM_LARAVEL.md) moves an application over, keeping its database, encrypted data, Sanctum tokens, and users.

## Documentation

[docs/README.md](docs/README.md) lists every guide. The same documentation is on the web, with search and a copy per release, at [xpsr.crysto.name.ng](https://xpsr.crysto.name.ng/docs/). What each release promises is in [docs/VERSIONING.md](docs/VERSIONING.md), what changed in [CHANGELOG.md](CHANGELOG.md), and the steps between minor versions in [UPGRADE.md](UPGRADE.md).

## Packages

The skeleton installs the framework and the FastRoute adapter; every other package is optional and has a README with its setup. [docs/PACKAGES.md](docs/PACKAGES.md) has their dependencies.

| Package | What it gives |
|---|---|
| `crystoline/xpsr` | The application skeleton: `app/`, `bootstrap/`, `config/`, `routes/`, `public/`, `views/`, the `xpsr` executable, and a Docker setup for development. What `composer create-project` gives you. |
| [`crystoline/xpsr-framework`](packages/framework/README.md) | Lifecycle, container, HTTP kernel, routing contracts, providers, events, sessions, authentication, console. |
| [`crystoline/xpsr-router-fastroute`](packages/router-fastroute/README.md) | FastRoute adapter, the skeleton's default. |
| [`crystoline/xpsr-router-sunrise`](packages/router-sunrise/README.md) | Sunrise adapter. |
| [`crystoline/xpsr-installer`](packages/installer/README.md) | The `xpsr` command outside a project: `xpsr new` creates an application, and other commands run the nearest project's own `xpsr`. Installed globally. |
| [`crystoline/xpsr-cache`](packages/cache/README.md) | Application cache: PSR-16 and PSR-6, `cache:clear`, rate limiting. |
| [`crystoline/xpsr-database`](packages/database/README.md) | Database connections (Doctrine DBAL) and migrations. |
| [`crystoline/xpsr-orm-doctrine`](packages/orm-doctrine/README.md) | Doctrine ORM on XPSR's connection, migrations generated from entities. |
| [`crystoline/xpsr-orm-eloquent`](packages/orm-eloquent/README.md) | Eloquent models on XPSR's connection and transactions, with XPSR's migrations. |
| [`crystoline/xpsr-orm-cycle`](packages/orm-cycle/README.md) | Cycle ORM on XPSR's connections and transactions, with migrations generated from the entities, entity behaviors, and a user provider. |
| [`crystoline/xpsr-validation`](packages/validation/README.md) | Request validation: input classes, Symfony Validator rules, 422 with field errors. |
| [`crystoline/xpsr-api`](packages/api/README.md) | JSON API responses: resources with conditional fields and a data envelope, and pagination of DBAL, Doctrine, Eloquent, and Cycle queries. |
| [`crystoline/xpsr-http-client`](packages/http-client/README.md) | Outgoing HTTP: PSR-18 and named clients, a fake for tests. |
| [`crystoline/xpsr-log-monolog`](packages/log-monolog/README.md) | Monolog logging: stderr, file, daily, or syslog; line or JSON; request IDs. |
| [`crystoline/xpsr-auth-jwt`](packages/auth-jwt/README.md) | JWT sign-in for APIs: the default `api` guard, an issuer, `auth:jwt-secret`. |
| [`crystoline/xpsr-auth-oauth2-server`](packages/auth-oauth2-server/README.md) | An OAuth2 authorization server: client credentials, authorization code with PKCE, refresh tokens, a consent page, and the `oauth2` guard driver, so other applications can call the API for your users. |
| [`crystoline/xpsr-queue`](packages/queue/README.md) | Queued jobs on Symfony Messenger: sync, database, Redis, AMQP, or SQS; retries and a failed jobs table. |
| [`crystoline/xpsr-mail`](packages/mail/README.md) | Email on Symfony Mailer: SMTP, sendmail, or a provider by DSN; views as bodies; queued sending. |
| [`crystoline/xpsr-lock`](packages/lock/README.md) | Locks across processes and servers: files, Redis, Redlock, or the database. |
| [`crystoline/xpsr-notifications`](packages/notifications/README.md) | Notifications by email, SMS, an in-app inbox, and browser push, on Laravel's tables. |
| [`crystoline/xpsr-scheduler`](packages/scheduler/README.md) | Scheduled tasks from one cron entry: commands, callbacks, and queued jobs, with overlap locks. |
| [`crystoline/xpsr-storage`](packages/storage/README.md) | File storage on Flysystem: named disks (local, S3, or your own driver), uploads, and downloads. |
| [`crystoline/xpsr-webhooks`](packages/webhooks/README.md) | Webhooks: signed deliveries to subscribed endpoints with retries and a delivery log, and verification of the webhooks providers send. |
| [`crystoline/xpsr-audit`](packages/audit/README.md) | An audit log: who changed what, when, and from where, with before and after values, by hand or automatically for models and entities. |
| [`crystoline/xpsr-telemetry`](packages/telemetry/README.md) | Distributed tracing on OpenTelemetry: requests, queries, outgoing calls, jobs, commands, and tasks as one trace, exported over OTLP. |
| [`crystoline/xpsr-health`](packages/health/README.md) | Health checks: live and ready endpoints and a health command checking the database, cache, queue and workers, scheduler, locks, disk, and HTTP dependencies. |
| [`crystoline/xpsr-translation`](packages/translation/README.md) | Translations: message files per locale, the request's locale from its route, session, cookie, or `Accept-Language` header, `trans()` in code and templates, and translated validation messages. |
| [`crystoline/xpsr-broadcast`](packages/broadcast/README.md) | Live updates to browsers through a Mercure hub: publish to topics from code, events, or a queue job after the transaction commits, private topics with a cookie for the signed-in user, and `hub_url()` in templates. |
| [`crystoline/xpsr-view-plates`](packages/view-plates/README.md) | Plates views (native PHP templates) in place of Twig. |
| [`crystoline/xpsr-view-latte`](packages/view-latte/README.md) | Latte views, escaped by context, in place of Twig. |
| [`crystoline/xpsr-view-blade`](packages/view-blade/README.md) | Blade views without the rest of Laravel, in place of Twig. |

## Contributing

Issues and pull requests go to this repository; the `crystoline/xpsr-*` repositories are read-only mirrors published for Composer. [CONTRIBUTING.md](CONTRIBUTING.md) has the setup, the checks, where tests go, and how a release is made. To report a vulnerability, see [SECURITY.md](SECURITY.md).

XPSR is released under the [MIT License](LICENSE).
