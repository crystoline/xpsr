# crystoline/xpsr-lock

Locks for XPSR applications, on [Symfony Lock](https://symfony.com/doc/current/components/lock.html). A lock lets one process at a time do a piece of work, across processes and servers. Examples: capturing a payment, sending the nightly report, or refreshing a shared access token.

```sh
composer require crystoline/xpsr-lock
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Lock\LockServiceRegistry::class,
```

One server can use the default store, files in `var/locks`. For several servers, create `config/lock.php`:

```php
return [
    // flock (files in var/locks, one server), redis://redis:6379 (one Redis server),
    // redlock (the servers below), database (the application's database), or a Symfony Lock DSN.
    'store' => env('LOCK_STORE', 'flock'),
    // For redlock: three or more independent Redis servers. A lock needs more than half of them.
    'redlock' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOCK_REDLOCK', ''))))),
    'database' => ['connection' => null, 'table' => 'lock_keys'],
    'prefix' => 'app',          // in front of every key, so applications can share a store
    'ttl' => '5 minutes',       // how long a lock lasts unless it is refreshed
];
```

## Taking a lock

```php
use Crystoline\Xpsr\Lock\Locks;

$receipt = $locks->run("payment:{$payment->id}", fn() => $gateway->capture($payment), ttl: '30 seconds', wait: '5 seconds');
```

`run()` takes the lock, runs the callback, returns its result, and releases the lock, also when the callback throws. If another process holds the lock, `run()` waits up to `wait` for it. The default `wait` is 0, which tries once. Then it throws `Crystoline\Xpsr\Lock\LockNotAcquiredException`, whose `key` names the lock. `ttl` is how long the lock lasts if it is not released, for example because the process died. It is given in seconds or as a period, and defaults to `lock.ttl`.

For work that may take longer than the `ttl`, the callback receives the lock and can extend it:

```php
use Symfony\Component\Lock\LockInterface;

$locks->run('import:products', function (LockInterface $lock) use ($rows): void {
    foreach (array_chunk($rows, 500) as $chunk) {
        $this->import($chunk);
        $lock->refresh();       // another ttl from now
    }
}, ttl: '1 minute');
```

`lock()` returns the Symfony lock, for full control:

```php
$lock = $locks->lock('reports:nightly', ttl: '1 hour');
if (!$lock->acquire()) {
    return;                     // another server is sending it
}
try {
    $reports->sendNightly();
} finally {
    $lock->release();
}
```

`Symfony\Component\Lock\LockFactory` is also a service, with the same store and without the prefix.

## Stores

| `lock.store` | Locks are kept | Use it for |
|---|---|---|
| `flock`, the default | in files in `var/locks` | one server. A lock ends with its process, so `ttl` does not apply. |
| `redis://redis:6379` | on one Redis server | several servers. Needs `symfony/cache`, and the phpredis extension or `predis/predis`. |
| `redlock` | on the Redis servers in `lock.redlock` | several servers, when locks must not depend on one Redis server. Same requirements as `redis://`. |
| `database` | in `lock.database.table` of the application's database | several servers without Redis. Needs `crystoline/xpsr-database`. |
| another [Symfony Lock DSN](https://symfony.com/doc/current/components/lock.html#available-stores) | for example `postgresql+advisory://…`, `memcached://…`, `semaphore`, or `in-memory` | stores the others do not cover; `in-memory` for tests. |

### Redlock

[Redlock](https://redis.io/docs/latest/develop/clients/patterns/distributed-locks/) takes the lock on several independent Redis servers, which are not replicas of one another. The lock is held when more than half of the servers agreed within its time to live. One server of three, or two of five, can be down. Use an odd number of servers, each with its own DSN:

```env
LOCK_STORE=redlock
LOCK_REDLOCK=redis://redis-1:6379,redis://redis-2:6379,redis://redis-3:6379
```

Symfony Lock's `CombinedStore` with `ConsensusStrategy` does the work. Each lock gets a random token, only its holder can extend or release it, and a server that fails or does not answer counts as a refusal.

### Database

```sh
php xpsr make:lock-table
php xpsr migrate
```

The store uses a database connection of its own, so locks stay out of the application's transactions. A lock taken inside a transaction is visible to other processes at once, and rolling the transaction back does not undo it. Use MySQL, MariaDB, or PostgreSQL. With SQLite, a transaction that has written blocks the store's connection until the transaction ends, and one server is better served by `flock` anyway.

## What a lock guarantees

A lock keeps two processes from doing the same work at the same time. It cannot guarantee that work happens exactly once, whichever store is used, Redlock included:

- Its holder can stall past the time to live, for example on a slow network call or a long pause. Another process then takes the lock, and both run.
- Redis keeps locks in memory, so a server that restarts forgets the locks it held.

For payments and other work that must happen once, the lock avoids duplicate work and the database makes a second run harmless:

```php
use Doctrine\DBAL\Connection;

$locks->run("payment:{$id}", function () use ($db, $gateway, $id): void {
    $db->transactional(function (Connection $db) use ($gateway, $id): void {
        $payment = $db->fetchAssociative('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
        if ($payment['status'] !== 'pending') {
            return;             // another run captured it
        }
        // The idempotency key makes the gateway answer a repeated request instead of charging twice.
        $gateway->capture($payment['amount'], idempotencyKey: $payment['idempotency_key']);
        $db->update('payments', ['status' => 'captured'], ['id' => $id]);
    });
}, ttl: '30 seconds', wait: '5 seconds');
```

## Other packages

`withoutOverlapping()` in [`crystoline/xpsr-scheduler`](../scheduler/README.md) uses these locks when `LockServiceRegistry` is in `app.registry` and `scheduler.lock` is not set.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Lock\Locks` | `run()` and `lock()`, with `lock.prefix`. Shared. |
| `Symfony\Component\Lock\LockFactory` | Symfony's factory for the same store, without the prefix. Shared. |
| `Symfony\Component\Lock\PersistingStoreInterface` | The store. Shared. |
| `Crystoline\Xpsr\Lock\LockOptions` | The validated `lock` configuration. |

## Testing

Set `'store' => 'in-memory'` in `config/testing/lock.php`, so tests leave no files in `var/locks`. To test code that must give up when another process holds a lock, take the lock first:

```php
$held = $this->application()->get(Locks::class)->lock('reports:nightly');
$held->acquire();
```
