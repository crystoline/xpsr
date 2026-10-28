# crystoline/xpsr-log-monolog

[Monolog](https://github.com/Seldaek/monolog) as the XPSR application logger: logs to stderr, a file, daily files, or syslog, as plain lines or JSON, with a request ID on every line.

```sh
composer require crystoline/xpsr-log-monolog
```

Add the provider to `app.registry` in `config/app.php`, after `FactoryRegistry` (which binds the default logger), and the request ID middleware first in `app.middleware`:

```php
'registry' => [
    FactoryRegistry::class,
    Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry::class,
    // ...
],
'middleware' => [
    Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware::class,
    CorsMiddleware::class,
    // ...
],
```

Everything that logs through `Psr\Log\LoggerInterface`, including the exception handler's error reports, now goes through Monolog. Without the package, XPSR's default logger writes through PHP's `error_log()`.

## Configuration

Set environment variables, or override them in `config/logging.php`:

| Setting | Environment | Default | Values |
|---|---|---|---|
| `channel` | `LOG_CHANNEL` | `stderr` | `stderr`: standard error, which Docker, systemd, and PHP-FPM (with `catch_workers_output`) collect. `file`: one file. `daily`: one file per day, as `app-2026-10-28.log`, keeping `days` files. `syslog`: the system log. |
| `format` | `LOG_FORMAT` | `json` when `APP_ENV=production`, otherwise `line` | `line`: readable text. `json`: one JSON object per line, for log collectors. |
| `level` | `LOG_LEVEL` | `debug` | The lowest level written: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, or `emergency`. |
| `path` | | `var/log/app.log` | The file for `file` and `daily`, relative to the project root. The directory is created if needed and must be writable by the web server and CLI users. |
| `days` | | `14` | How many daily files to keep. |
| `name` | | `app.name` | The channel name on each line. |

```php
// config/logging.php
return ['channel' => 'daily', 'days' => 30];
```

Invalid values fail when the logger is first used, with the setting's name.

A line looks like this; context values that fill `{placeholders}` in the message are removed from the context:

```text
[2026-10-28T09:15:02.481233+00:00] Shop.WARNING: Order 42 failed {"reason":"card declined"} {"request_id":"4f9c0b6e1d2a4c5e8f7a6b5c4d3e2f10"}
```

Exceptions passed as `['exception' => $exception]`, as the exception handler does, are written with their stack trace.

## Request IDs

`RequestIdMiddleware` gives each request an ID. It keeps an incoming `X-Request-Id` header of up to 128 letters, digits, `.`, `_`, or `-`, as set by load balancers and calling services, and generates a random 32-character ID otherwise. The ID:

- is added as `request_id` to every log line written while the request is handled, including the error report for an exception;
- is available to your code as the `request_id` request attribute, and as `Crystoline\Xpsr\Log\Monolog\RequestId::get()`;
- is returned in the `X-Request-Id` response header, so a user reporting a problem can quote it. Error pages rendered for exceptions that escape every middleware do not carry the header; their log lines still carry the ID.

Console commands have no request ID.

## Services

| Service | Provides |
|---|---|
| `Psr\Log\LoggerInterface` | The Monolog logger (also `Monolog\Logger`). Shared. |
| `Crystoline\Xpsr\Log\Monolog\RequestId` | The current request's ID. Shared. |
| `Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware` | Sets it for each request. |

To add Monolog handlers of your own, such as an error-tracking service, bind `Psr\Log\LoggerInterface` yourself in a provider listed after this one, or extend the logger in a provider's `boot()`: `$this->container->get(Monolog\Logger::class)->pushHandler(...)`.

Requires PHP 8.4+.
