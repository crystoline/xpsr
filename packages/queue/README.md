# crystoline/xpsr-queue

Queued jobs for XPSR applications, on [Symfony Messenger](https://symfony.com/doc/current/messenger.html). A job is a class that holds its data and does its own work; a worker runs it later, with retries, and keeps it in a failed jobs table when every attempt fails.

```sh
composer require crystoline/xpsr-queue
```

Add the provider to `app.registry` in `config/app.php`, after the database provider:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
Crystoline\Xpsr\Queue\QueueServiceRegistry::class,
```

## Jobs

```sh
php xpsr make:job SendWelcomeEmail
```

```php
namespace App\Job;

use Crystoline\Xpsr\Queue\JobInterface;

final class SendWelcomeEmail implements JobInterface
{
    public function __construct(public readonly int $userId) {}

    public function handle(Mailer $mailer, UserRepository $users): void
    {
        $mailer->send(new WelcomeEmail($users->find($this->userId)));
    }
}
```

The constructor holds the job's data; `handle()` does the work, and its parameters are services from the container, by type. Keep the data to IDs and plain values: the job is serialized when it is queued and runs later, perhaps on another server, so load records again in `handle()`.

```php
use Crystoline\Xpsr\Queue\QueueInterface;

$queue->dispatch(new SendWelcomeEmail($user->id));
$queue->dispatch(new SendInvoice($invoice->id), delay: '10 minutes');   // or seconds: 600
$queue->dispatch(new ResizeImage($upload->id), queue: 'images');        // a transport from config/queue.php
```

A job class can choose its transport with `#[Crystoline\Xpsr\Queue\Attribute\OnQueue('images')]`. Jobs can dispatch more jobs.

## Transports

`config/queue.php`:

```php
return [
    'default' => 'default',
    'transports' => [
        'default' => ['dsn' => env('QUEUE_DSN', 'sync://')],
        'images' => ['dsn' => 'database://default?queue_name=images', 'retry' => ['max_retries' => 5]],
    ],
];
```

| DSN | Jobs are kept | Needs |
|---|---|---|
| `sync://` (the default) | nowhere: they run at once, inside `dispatch()`, and their exceptions reach the caller | — |
| `in-memory://` | in memory, for tests | — |
| `database://<connection>?queue_name=<name>` | in the `queue_messages` table on an XPSR connection (the default one without a name) | `php xpsr make:queue-table`, then `migrate` |
| `redis://host:6379/<stream>` | in a Redis stream | `symfony/redis-messenger`, `ext-redis` |
| `amqp://user:pass@host:5672/%2f/<queue>` | in RabbitMQ | `symfony/amqp-messenger`, `ext-amqp` |
| `sqs://...` or the queue's `https://sqs...` URL | in Amazon SQS | `symfony/amazon-sqs-messenger` |

A transport's `options` are passed to Messenger's transport factory; see the Messenger documentation for each bridge. The database transport's table is created by a migration, never on its own.

## Workers

```sh
php xpsr queue:work                       # the default transport
php xpsr queue:work images default        # several, the first one first
php xpsr queue:work --limit=1000 --time-limit=3600 --memory-limit=256M
php xpsr queue:work --stop-when-empty     # once, for cron or tests
```

A worker runs until it is stopped. SIGTERM and SIGINT stop it after the current job. Keep workers running with a process supervisor (systemd, Supervisor, or the container platform), and run `php xpsr queue:restart` after each deploy: running workers then stop after their current job, and the supervisor starts them with the new code. `queue:restart` needs a shared cache, `crystoline/xpsr-cache`.

## Failures and retries

A job that throws is tried again after a delay that grows: by default 3 more times, after 1, 2, and 4 seconds, up to 1 hour apart.

```php
'retry' => ['max_retries' => 3, 'delay' => '1 second', 'multiplier' => 2, 'max_delay' => '1 hour'],
```

A transport's own `retry` replaces these. Throw `Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException` for a failure that a retry cannot fix.

After the last attempt, the job goes to the `failed_jobs` table (created by `make:queue-table` with the queue table), and every failed attempt is logged.

```sh
php xpsr queue:failed            # ID, job, transport, time, error
php xpsr queue:retry 12 13       # queue them again on their transport; --all for every one
php xpsr queue:forget 12         # remove without running; --all for every one
```

Set `'failed' => false` to only log jobs that ran out of retries, or `'failed' => ['table' => ..., 'connection' => ...]` for another table or connection.

## Unique jobs

A `#[Unique]` job is queued once: while it is queued or running, dispatching an identical job drops the new one. Jobs are identical when they are of the same class and their `uniqueId()` methods return the same value. A job without `uniqueId()` is unique by its class alone.

```php
use Crystoline\Xpsr\Queue\Attribute\Unique;

#[Unique(for: '1 hour')]
final class SyncWallet implements JobInterface
{
    public function __construct(public readonly int $walletId) {}

    public function uniqueId(): string
    {
        return (string) $this->walletId;
    }

    public function handle(Wallets $wallets): void { ... }
}
```

`dispatch()` takes a lock for the job. The lock is released when the job succeeds, or when it fails for the last time, after its retries. `for` is required: it is the most the lock lasts, so a worker that dies mid-job cannot hold it for ever. Keep it longer than the job can wait and run, retries included; after it, an identical job can be queued again.

With `#[Unique(for: '1 hour', until: 'processing')]`, the lock is released when the job starts, so an identical job can be queued while the first one runs, but not while it waits.

Unique jobs need locks shared by the web process and the worker, from [crystoline/xpsr-lock](../lock/README.md):

```sh
composer require crystoline/xpsr-lock
```

List `Crystoline\Xpsr\Lock\LockServiceRegistry` in `app.registry`, and set `lock.store` to Redis, `redlock`, or `database`. The process that dispatches a job takes the lock and the worker releases it, so the default `flock` store cannot be used: its locks belong to the process that took them. With it, dispatching a unique job throws an exception that says so, except on `sync://`, where the job runs in the same process.

A drop is logged at the debug level. `php xpsr queue:retry` leaves a failed unique job in the failed jobs table while an identical job is queued or running, and says so.

Use a unique job to avoid queuing the same work twice. Uniqueness is not a guarantee that work runs only once: after `for`, or when the lock store is lost, an identical job can be queued again. Keep that guarantee in the database, as [What a lock guarantees](../lock/README.md#what-a-lock-guarantees) explains.

## Request IDs

With [crystoline/xpsr-log-monolog](../log-monolog/README.md), a job carries the ID of the request that dispatched it. The worker sets that ID while the job runs, so the job's log lines carry the same `request_id` as the request's. So do the lines about its failure or retry. Jobs dispatched outside a request, for example by a command, carry none.

## Testing

```php
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Queue\Testing\FakeQueue;

$this->swap(QueueInterface::class, $queue = new FakeQueue());
$this->postJson('/users', ['email' => 'ada@example.com']);

self::assertCount(1, $queue->dispatched(SendWelcomeEmail::class));
self::assertSame(7, $queue->dispatched(SendWelcomeEmail::class)[0]->userId);
$queue->run($this->application()->get(JobRunner::class));   // when the test needs the jobs' effects
```

The fake drops a `#[Unique]` job while an identical one is waiting in it or running in `run()`, and lists it in `$queue->dropped()`. It does not count `for`: it keeps the lock until the job runs.

With `QUEUE_DSN=sync://` in tests, jobs instead run at once.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Queue\QueueInterface` | `dispatch()`. Shared. |
| `Crystoline\Xpsr\Queue\JobRunner` | Runs a job's `handle()` with services from the container. |
| `Crystoline\Xpsr\Queue\Failed\FailedJobStore` | The failed jobs table. |
| `Symfony\Component\Messenger\MessageBusInterface` | The Messenger bus the queue uses. |

This package's tests run against SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17.

Requires PHP 8.4+.
