# crystoline/xpsr-cache

Caching for XPSR applications: one application cache, available as PSR-16 (`Psr\SimpleCache\CacheInterface`) and PSR-6 (`Psr\Cache\CacheItemPoolInterface`) over the same storage, backed by [Symfony Cache](https://symfony.com/doc/current/components/cache.html), plus a `php xpsr cache:clear` command.

```sh
composer require crystoline/xpsr-cache
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Cache\CacheServiceRegistry::class,
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
