# crystoline/xpsr-cache

Caching for XPSR applications: one application cache, available as PSR-16 (`Psr\SimpleCache\CacheInterface`) and PSR-6 (`Psr\Cache\CacheItemPoolInterface`) over the same storage, backed by [Symfony Cache](https://symfony.com/doc/current/components/cache.html), plus a `php xpsr cache:clear` command and rate limiting for routes (see [Rate limiting](#rate-limiting)).

```sh
composer require crystoline/xpsr-cache
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Cache\CacheServiceRegistry::class,
```

Then inject the cache wherever you need it:

```php
public function __construct(private readonly \Psr\SimpleCache\CacheInterface $cache) {}

$report = $this->cache->get('report');
if ($report === null) {
    $report = $this->buildReport();
    $this->cache->set('report', $report, 600); // 10 minutes
}
```

Configuration is optional. Create `config/cache.php` to change the defaults:

```php
return [
    'driver' => 'file',            // file, array, null, or service
    'path' => 'var/cache/app',     // file driver; relative to the application root
    'namespace' => 'app',          // key prefix when storage is shared
    'default_ttl' => 0,            // seconds; 0 means no expiry
];
```

| Driver | Storage |
|---|---|
| `file` (default) | Files under `path`. The directory must be writable by the web server and the CLI user. |
| `array` | Memory of the current process only. Useful in tests. |
| `null` | Stores nothing. Disables caching without code changes. |
| `service` | Any PSR-6 pool from the container, named by `service`. Use this for Redis, APCu, Memcached, or a chain. |

For example, Redis with Symfony's adapter, bound in `config/factory.php`:

```php
'cache.redis' => static fn() => new Symfony\Component\Cache\Adapter\RedisAdapter(
    Symfony\Component\Cache\Adapter\RedisAdapter::createConnection(env('REDIS_URL', 'redis://localhost')),
    'app',
),
```

Then set `'driver' => 'service', 'service' => 'cache.redis'` in `config/cache.php`. Only the `file`, `array`, `null`, and `service` wiring is tested by this package; Redis itself is not.

Use keys made of letters, digits, `_`, and `.`. PSR-16 reserves `{}()/\@:`. Symfony Cache rejects reserved characters only while PHP assertions run (`zend.assertions=1`, the development default), to keep production fast. With the production `php.ini` they are accepted, so catch mistakes in development.

`php xpsr cache:clear` removes every item in the configured cache (for shared storage, only under its namespace) and exits with 1 if the storage reports a failure.

## Sessions

With `SESSION_STORE=cache` (or `'store' => 'cache'` in `config/session.php`), sessions are kept in this cache under `xpsr_session.<id>`, each with the session lifetime as its time to live. Use a cache that outlives requests, and a shared one such as Redis when there are several servers. `cache:clear` deletes every session. See the XPSR repository's `docs/SESSIONS.md`.

## Rate limiting

The package also limits how often clients may call routes, using [Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html) with a sliding window, and keeps the counts in this cache.

Name the limits in `config/rate_limit.php`:

```php
return [
    'limits' => [
        'api' => ['limit' => 60, 'interval' => '1 minute'],
        'login' => ['limit' => 5, 'interval' => '15 minutes'],
    ],
];
```

Then attach a limit to routes or groups:

```php
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;

$routes->post('/login', [LoginController::class, 'store'])->middleware(RateLimit::named('login'));
$routes->group('/api', static function (RouteCollection $routes): void {
    // ...
}, [RateLimit::named('api')]);
```

or apply one to every request by listing `RateLimit::named('api')` in `app.middleware` (after `CorsMiddleware`, so preflight requests do not count).

| Option | Meaning |
|---|---|
| `limit` | Requests allowed per interval, 1 or more. |
| `interval` | A period such as `1 minute`, `15 minutes`, or `1 hour`. With a sliding window, a client that used its allowance gets requests back gradually rather than all at once when a fixed period ends. |
| `key` | Who is counted. By default each client address, read through `app.trusted_proxies` so clients behind a load balancer are counted separately. Name a service implementing `Crystoline\Xpsr\Cache\RateLimit\ClientKeyResolverInterface` to count by user or API key instead. |

Each request uses one unit of the client's allowance in that limit. Responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Over the limit, the request stops with `Crystoline\Xpsr\Http\Exception\TooManyRequestsHttpException`, which the exception handler renders as 429 with `Retry-After` in seconds and the same headers. Invalid options fail when the limit is first used, with the setting's name.

Counts live in the application cache, so `cache:clear` resets them. The `file` driver works for one server; for several servers, use the `service` driver with a shared store such as Redis, so every server sees the same counts. Under heavy concurrency a client may get a request or two above the limit, because counts are not locked.
