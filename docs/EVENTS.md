# Events

XPSR includes a [PSR-14](https://www.php-fig.org/psr/psr-14/) event dispatcher. Code that wants to announce something dispatches an event object; listeners registered for that event react to it. The code that dispatches does not need to know who listens.

New applications have it enabled: `Crystoline\Xpsr\Registry\EventServiceRegistry` is listed in `app.registry` in `config/app.php`. To add it to an existing application, add that line and create `config/events.php` as shown below.

## Create an event and a listener

```sh
php xpsr make:event UserRegistered
php xpsr make:listener SendWelcomeEmail --event=UserRegistered
```

An event is any object. Give it readonly properties that describe what happened:

```php
namespace App\Events;

final class UserRegistered
{
    public function __construct(public readonly int $userId, public readonly string $email) {}
}
```

A listener is a class with an `__invoke()` method that takes the event. The container builds it, so it can ask for dependencies in its constructor:

```php
namespace App\Listeners;

use App\Events\UserRegistered;
use Psr\Log\LoggerInterface;

final class SendWelcomeEmail
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(UserRegistered $event): void
    {
        $this->logger->info('Welcome email queued for {email}.', ['email' => $event->email]);
    }
}
```

List the listener in `config/events.php`:

```php
return [
    'listeners' => [
        App\Events\UserRegistered::class => [App\Listeners\SendWelcomeEmail::class],
    ],
];
```

Then dispatch the event wherever it happens, with the dispatcher injected:

```php
use Psr\EventDispatcher\EventDispatcherInterface;

public function __construct(private readonly EventDispatcherInterface $events) {}

$this->events->dispatch(new UserRegistered($user->id, $user->email));
```

`php xpsr event:list` shows every listener in the order it runs. `--event=User` filters event classes by text and `--json` prints JSON.

## How listeners run

- **Matching.** A listener registered for a class also receives that class's subclasses, and a listener registered for an interface receives every event that implements it. To handle several events with one listener, give them a shared interface and listen for that.
- **Order.** Higher priorities run first. Listeners with the same priority run in the order they were registered: `config/events.php` first, in the order listed, then listeners added by providers.
- **Synchronous.** Listeners run immediately, one after another, in the process that dispatched the event. `dispatch()` returns the same event object, so listeners can record results on it for the caller.
- **Errors.** An exception thrown by a listener stops the dispatch and reaches the code that called `dispatch()`. Later listeners do not run.
- **Stopping.** An event that implements `Psr\EventDispatcher\StoppableEventInterface` can be stopped by a listener. `Crystoline\Xpsr\Events\StoppableEvent` is an optional base class with a `stopPropagation()` method. The dispatcher checks before every listener, so a stopped event reaches no further listeners, and an event that arrives already stopped reaches none.
- **Lazy construction.** Listener classes are built from the container only when an event reaches them, every time it does (unless the container shares them). A listener after a stopped event is never built.

## Listener forms

| Form | Example | Notes |
|---|---|---|
| Service ID | `SendWelcomeEmail::class` | The service must be callable: give the class an `__invoke()` method. |
| Service and method | `[AuditLog::class, 'onUserRegistered']` | The service is built from the container and the public method called. |
| Callable | `static fn (UserRegistered $event) => ...` | Closures, invokable objects, and `[$object, 'method']` pairs are called directly. They work in `config/events.php`, but class names keep it plain data. |

Malformed listeners are rejected when they are registered, with a message naming the event. An event class that does not exist is rejected too, because a listener for a misspelled class would never run. A listener service that turns out not to be callable fails when an event first reaches it.

## Registering listeners in code

Providers can add listeners in their `boot()` method, for example to set a priority or to register listeners only when a feature is enabled:

```php
use Crystoline\Xpsr\Events\ListenerProvider;

public function boot(): void
{
    $this->container->get(ListenerProvider::class)
        ->listen(UserRegistered::class, [AuditLog::class, 'record'], priority: 10);
}
```

## Services

| Service | Provides |
|---|---|
| `Psr\EventDispatcher\EventDispatcherInterface` | The dispatcher (also `Crystoline\Xpsr\Events\EventDispatcher`). Shared. |
| `Psr\EventDispatcher\ListenerProviderInterface` | The listener provider (also `Crystoline\Xpsr\Events\ListenerProvider`). Shared. |

The framework does not dispatch events of its own yet. Queued or asynchronous listeners are not provided; a listener that should not delay a response can hand the work to a queue once queue support exists.
