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
