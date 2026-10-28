# Container and provider lifecycle

The application owns a container. It no longer extends `ServiceContainer`. Runtime factories, controllers, and integrations can consume `Psr\Container\ContainerInterface` from `psr/container` 2.x. The framework's registration API is separate: `ContainerBuilderInterface` defines writes, while `Crystoline\Xpsr\Contracts\Container\ContainerInterface` combines registration and PSR-11 consumption for bootstrapping and providers.

```php
$application = require __DIR__ . '/../bootstrap/app.php';
$container = $application->getContainer();

$container->bind('request.context', stdClass::class);
$container->singleton('shared.context', stdClass::class);
$container->instance('feature.enabled', false);
$container->instance('optional.value', null);
$container->instance('literal.string', 'strlen');
$container->factory('request.id', static fn () => bin2hex(random_bytes(16)));
$container->factory('shared.id', static fn () => bin2hex(random_bytes(16)), shared: true);
$container->alias('context', 'shared.context');
```

These examples assume a script under an application subdirectory. Use the appropriate absolute bootstrap path for your entry point.

| Operation | Behavior |
| --- | --- |
| `bind($id, $class)` | Construct the class on each resolution. Omitting `$class` uses `$id` as the class name. |
| `singleton($id, $class)` | Construct the class once and reuse it for that binding. |
| `factory($id, $callable, shared: false)` | Invoke the callable with the PSR-11 container each time. Set `shared: true` to cache its result, including `null`. |
| `instance($id, $value)` | Return the exact value, including strings, arrays, nulls, closures, and invokable objects. Never invoke it. |
| `alias($alias, $target)` | Delegate to the target on every lookup, preserving the target's lifetime and observing subsequent target rebinding. |
| `has($id)` | Check definitions, deferred declarations, or whether a class is instantiable. Does not run factories, construct services, or load deferred providers. PHP class autoloading may occur. |
| `get($id)` | Resolve the entry or throw a PSR-11 exception. |

An invokable class is a service unless explicitly registered with `factory()`. Binding a class under an interface constructs that class independently of any binding under its concrete name. Use an alias when both identifiers must resolve through the same binding.

Rebinding clears the old cached value and lifetime for that identifier. Existing consumers are not rebuilt automatically. Rebinding an identifier while it is resolving is rejected. Alias cycles and constructor/factory cycles produce an exception showing the resolution path.

## Autowiring and errors

Unregistered, instantiable classes can be autowired as transient services. Named class/interface parameters resolve through the container; constructor defaults are used when no named dependency is available. Explicitly nullable named types can receive null when unresolved. Parent/self types are resolved relative to the declaring class. Variadic parameters receive no automatically discovered collection.

Required scalar, untyped, union, and intersection parameters need an explicit factory unless a constructor default is available. Session variables are never used for dependency injection. A registered dependency that fails to resolve propagates an error instead of silently falling back to a default.

Unknown identifiers raise `NotFoundExceptionInterface`. A known binding or autowirable class with a missing dependency raises `ContainerExceptionInterface`, preserving the underlying cause. `has()` returning true is not a promise that all dependencies can be constructed.

The container registers itself under PSR-11 and both framework container contracts. The application registers itself under `Application::class`; resolving PSR-11 returns the container, not the application. `Application::get()`, `has()`, and the older `build()` helper delegate to the container. Use `getContainer()` for registration; `build()` is retained as a deprecated convenience.

An alternative container can be passed as the second `Application` constructor argument. It must implement the combined framework registration contract, including deferred groups, or provide an adapter for it. A PSR-11 implementation alone does not standardize registration and is therefore insufficient for that constructor.

## The clock

Code that needs the current time should ask for `Psr\Clock\ClockInterface` (PSR-20) instead of calling `new DateTimeImmutable()` or `time()`, so tests can control time:

```php
use Psr\Clock\ClockInterface;

public function __construct(private readonly ClockInterface $clock) {}

$expiresAt = $this->clock->now()->modify('+15 minutes');
```

The application registers a `Symfony\Component\Clock\NativeClock` in the `app.timezone` time zone (UTC in new projects); an invalid time zone fails with the setting's name. `Symfony\Component\Clock\ClockInterface`, which adds `sleep()` and `withTimeZone()`, resolves to the same clock. In tests, `$clock = $this->freezeTime('2026-01-31 09:00:00')` from the skeleton's `TestCase` replaces it with a `MockClock` that stands still until `$clock->modify('+1 hour')` or `$clock->sleep(60)` moves it.

## Providers

Providers extend `ServiceRegistry` and are listed in `config/app.php` under `registry`. The existing names remain to avoid an unnecessary rename.

1. Provider classes are validated and duplicate classes are collapsed, preserving their first position.
2. Deferred service identifiers are reserved without executing provider `register()` methods.
3. Every eager provider registers its definitions in configured order.
4. Registered providers run `boot()` in configured order. All eager definitions are available by this point.
5. A deferred provider first requested later registers and boots on demand, once for its entire advertised group.

`register()` should define factories, class bindings, aliases, or values. Put startup side effects in `boot()`. Configuration and other already available core services may be consulted during registration. If registration resolves a deferred service, its provider registers immediately but its boot hook waits until eager registration finishes.

A deferred provider sets `protected bool $delayed = true` and returns a non-empty list of unique identifiers from `providedServices()`. Its registration must define all advertised identifiers. Missing definitions, circular registration, or failures during on-demand registration/boot produce a container exception; a group whose on-demand load failed does not retry or expose partially registered services on later lookups. If a provider was loaded during eager registration and then fails in the application's boot phase, application boot fails: discard that application and container rather than using services resolved during the failed startup.

Deferred identifiers must not collide with existing definitions or other deferred groups. An unresolved deferred definition cannot be silently rebound: replace/remove its provider in configuration, or resolve it successfully before rebinding. This prevents a later lazy load from undoing an application override. Once a group has failed, explicit rebinding can replace an individual failed entry; rebuilding the application is preferred after a provider lifecycle failure.

Application boot is idempotent. Recursive boot fails clearly. A failed eager lifecycle is remembered so another `boot()` call cannot repeat partial startup side effects. Deferred boot hooks may consume services their own provider has already defined.

## Migrating earlier bindings

- Change `bind($id, $closure)` to `factory($id, $closure)`.
- Change `singleton($id, $closure)` to `factory($id, $closure, shared: true)`.
- Change `bind($id, $objectOrValue)` to `instance($id, $objectOrValue)`.
- Use `alias()` when an identifier should delegate to another configured binding.
- Change application-level writes to `$application->getContainer()->...`.
- Provider constructors should accept the framework container contract rather than the concrete `ServiceContainer` class.

The `config/factory.php` registry explicitly interprets entries as factories. Entries may be callables or invokable factory class names; retrieving the same invokable class directly still returns the object. This configuration path no longer depends on implicit invocation in the container.
