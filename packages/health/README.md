# crystoline/xpsr-health

Health checks for XPSR applications. Two endpoints answer a load balancer, Kubernetes, Docker, or an uptime monitor: whether this instance is up, and whether the things the application depends on are working. A command runs the same checks from the shell. The checks come with the package for what you have installed: the database, the cache, the queue and its workers, the scheduler, the locks, the disk, and the HTTP services you name; your own checks join them.

```sh
composer require crystoline/xpsr-health
```

Add the provider to `app.registry` in `config/app.php`, after the providers of the packages it checks, and the two routes to a route file:

```php
// config/app.php
'registry' => [
    // ...
    Crystoline\Xpsr\Health\HealthServiceRegistry::class,
],

// routes/api.php
use Crystoline\Xpsr\Health\Http\HealthController;

$routes->get('/health', [HealthController::class, 'live']);
$routes->get('/health/ready', [HealthController::class, 'ready']);
```

## The endpoints

**`GET /health`** answers `200 {"status":"ok"}` as soon as the application boots: the process is up and can serve. Point a liveness probe at it.

**`GET /health/ready`** runs the checks and answers 200, or 503 when one fails, with the report:

```json
{
    "status": "failing",
    "checked_at": "2026-11-03T10:00:00+00:00",
    "checks": {
        "database": {"status": "ok", "message": "Connected", "details": {"main": {"milliseconds": 0.4}}, "milliseconds": 0.5},
        "cache": {"status": "ok", "message": "Written and read back", "details": [], "milliseconds": 0.1},
        "queue": {"status": "warning", "message": "3 failed jobs", "details": {"default": {"waiting": 12}, "failed": 3}, "milliseconds": 2.1},
        "workers": {"status": "failing", "message": "No worker has reported on \"default\"", "details": {"default": {"last_seen": null}}, "milliseconds": 0.1},
        "scheduler": {"status": "ok", "message": "schedule:run reported 20 seconds ago", "details": {"last_run": "2026-11-03T09:59:40+00:00", "seconds_ago": 20}, "milliseconds": 0.1},
        "locks": {"status": "ok", "message": "Acquired and released", "details": [], "milliseconds": 0.3},
        "disk": {"status": "ok", "message": "41.2% free (82.4 GB of 200.0 GB)", "details": {"path": "/srv/app", "free_bytes": 88470000000, "total_bytes": 214700000000, "free_percent": 41.2}, "milliseconds": 0.0}
    }
}
```

A warning keeps the 200: it is something to look at, not a reason to take the instance out of rotation. Point a readiness probe at it, and alert on 503.

The messages and details describe your infrastructure, so they are shown only to a request from a private or loopback address (a load balancer, an operator on the network) or one carrying the secret of `health.secret`, in the `X-Health-Secret` header or the `secret` query parameter. Everyone else sees the statuses alone. The client address respects `app.trusted_proxies`. Both responses carry `Cache-Control: no-store`.

## The command

```sh
php xpsr health            # a table of the checks; exit code 1 when one fails
php xpsr health --json     # the report with details
php xpsr health --strict   # exit code 1 on a warning too
```

The command runs the checks now, even when a report is cached. Docker's `HEALTHCHECK CMD php xpsr health` and a cron entry that mails on failure are its uses.

## The checks

| Check | Needs | What it does |
|---|---|---|
| `database` | [`crystoline/xpsr-database`](../database/README.md) | Runs the platform's dummy query on each connection of `database.connections` (or `health.connections`). One that fails fails the check. |
| `cache` | [`crystoline/xpsr-cache`](../cache/README.md) | Writes a value, reads it back, and deletes it. |
| `queue` | [`crystoline/xpsr-queue`](../queue/README.md) | Asks each transport for its waiting jobs, which fails when the transport cannot be reached, and warns when the failed jobs table holds more than `health.failed_jobs` jobs. |
| `workers` | the queue and cache packages | Reads the heartbeat `queue:work` leaves in the cache for each transport it works on. No heartbeat, or one older than `health.worker_stale`, fails the check. With only `sync://` transports, nothing to check. |
| `scheduler` | [`crystoline/xpsr-scheduler`](../scheduler/README.md) and the cache package | The package adds a `health:heartbeat` task to the schedule, every minute, that writes the time to the cache. No heartbeat, or one older than `health.schedule_stale`, fails the check: `schedule:run` is not running. |
| `locks` | [`crystoline/xpsr-lock`](../lock/README.md) | Takes and releases a lock, so the lock store (Redis, the database, or the files) answers. A warning while another instance holds the probe lock. |
| `disk` | | The free space on the disk of the project root, or of `health.disk.path`: a warning below `health.disk.warning` percent, failing below `health.disk.failing`. |
| `http` | [`crystoline/xpsr-http-client`](../http-client/README.md) | Fetches each URL of `health.http`, such as a payment gateway's status page. Anything but a 2xx answer fails the check. |

A check that throws is a failing result with the exception's message. Each result carries the time the check took.

### Your own checks

A check implements `CheckInterface`: `name()` is its key in the report, `check()` answers with a `Result`:

```php
use Crystoline\Xpsr\Health\CheckInterface;
use Crystoline\Xpsr\Health\Result;

final class GatewayCheck implements CheckInterface
{
    public function __construct(private readonly Gateway $gateway) {}

    public function name(): string
    {
        return 'gateway';
    }

    public function check(): Result
    {
        $balance = $this->gateway->balance();

        return $balance > 0
            ? Result::ok("Balance {$balance}", ['balance' => $balance])
            : Result::warning('The gateway balance is empty', ['balance' => $balance]);
    }
}
```

List it in `health.checks`; it is built by the container, so its constructor takes services. `Result::ok()`, `warning()`, and `failing()` take a message and details (scalars, lists, and maps of them).

## Configuration

`config/health.php` is optional:

```php
return [
    'checks' => [App\Health\GatewayCheck::class],   // your own checks, after the built-in ones
    'skip' => [],                                   // built-in checks not to run, such as ['disk']
    'secret' => env('HEALTH_SECRET'),               // shows details to anyone who sends it
    'cache' => 5,                                   // seconds a report is kept; 0 runs the checks on every request
    'failed_jobs' => 0,                             // the queue check warns above this many failed jobs
    'worker_stale' => '5 minutes',                  // a worker silent for longer counts as gone
    'schedule_stale' => '3 minutes',                // the same for schedule:run
    'connections' => null,                          // the connections to check; null for all
    'disk' => ['path' => null, 'warning' => 10, 'failing' => 5],   // percent of free space
    'http' => [
        'gateway' => 'https://api.gateway.example/health',
    ],
];
```

Invalid values fail when the checks are first used, with the setting's name.

**Caching.** A report is kept in the application cache for `health.cache` seconds, so a load balancer probing every second does not run the checks every second, and several instances behind one cache share the report. The command always runs the checks.

**The event.** `Crystoline\Xpsr\Health\Event\HealthChecked` is dispatched each time the checks run, with the `Report`; a cached answer does not dispatch it. Listen to it in `config/events.php` to alert on a failing report, or to record it.

**Reading a report in code.** `Health::check()` gives the `Report`: `status()`, `isFailing()`, `result('database')`, `results`, `toArray($details)`. `check(fresh: true)` runs the checks regardless of the cache.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Health\Health` | `check()`: the `Report`; `checks()`: the checks that run. Shared. |
| `Crystoline\Xpsr\Health\Http\HealthController` | `live()` and `ready()`, for a route file. |
| `Crystoline\Xpsr\Health\Exposure` | Whether a request may see a report's details. Shared. |

Not provided: a Prometheus metrics endpoint (metrics belong with the telemetry package when they come), a status page with history, and sending alerts itself (the event is the hook).

Requires PHP 8.4+. The database, cache, queue, scheduler, lock, and HTTP client packages are optional and detected.
