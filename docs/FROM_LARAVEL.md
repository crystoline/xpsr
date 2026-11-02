# Coming from Laravel

This guide moves a Laravel application, such as a JSON API with Sanctum tokens, queues, and notifications, to XPSR. Much of what Laravel stores carries over as it is, so users keep their accounts, tokens, and data. The code changes more: XPSR passes services in by type instead of using facades, and its requests and responses are PSR-7 messages.

## What carries over

| Laravel's | In XPSR |
|---|---|
| `APP_KEY` and the values encrypted with it | Read and written in the same format, including Eloquent's encrypted casts. Copy `APP_KEY` and `APP_PREVIOUS_KEYS`. See [Encryption](ENCRYPTION.md#coming-from-laravel). |
| Sanctum tokens in `personal_access_tokens` | Accepted by the `sanctum` guard driver; abilities are scopes. See [Authentication](AUTH.md#laravel-sanctum-tokens). |
| bcrypt password hashes | Verified, and upgraded to Argon2id when the user next signs in. |
| The database | `php xpsr migrate:baseline` starts XPSR's migrations from it. See [the database package](../packages/database/README.md#starting-from-an-existing-database). |
| Eloquent models | Run on XPSR's connections with [`crystoline/xpsr-orm-eloquent`](../packages/orm-eloquent/README.md), after the changes in [step 4](#4-models). |
| The `notifications` table, and `push_subscriptions` from `laravel-notification-channels/webpush` | Read and written as Laravel does, with the same `VAPID_*` keys. See [the notifications package](../packages/notifications/README.md#coming-from-laravel). |

These do not carry over:

- **Sessions and cookies.** XPSR neither reads Laravel's sessions nor decrypts its cookies, so people signed in through the browser sign in once more. Sanctum's cookie sign-in for single-page apps is not supported; use XPSR's session guard.
- **Queued jobs.** XPSR's workers cannot run jobs Laravel queued. Let Laravel's workers empty the queues before XPSR takes over.
- **The cache**, rate limit counts, and locks start empty.

## Moving, step by step

### 1. Create the project

```sh
composer create-project crystoline/xpsr my-api
cd my-api
php xpsr setup --router=fastroute --preset=api
composer require crystoline/xpsr-database crystoline/xpsr-orm-eloquent crystoline/xpsr-validation \
  crystoline/xpsr-cache crystoline/xpsr-lock crystoline/xpsr-queue crystoline/xpsr-mail \
  crystoline/xpsr-notifications crystoline/xpsr-scheduler crystoline/xpsr-storage \
  crystoline/xpsr-http-client crystoline/xpsr-log-monolog
```

Install only the packages the application uses. Each one has a provider to list in `app.registry` in `config/app.php`, database first:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry::class,
Crystoline\Xpsr\Validation\Symfony\ValidationServiceRegistry::class,
Crystoline\Xpsr\Cache\CacheServiceRegistry::class,
Crystoline\Xpsr\Lock\LockServiceRegistry::class,
Crystoline\Xpsr\Queue\QueueServiceRegistry::class,
Crystoline\Xpsr\Mail\MailServiceRegistry::class,
Crystoline\Xpsr\Notifications\NotificationServiceRegistry::class,
Crystoline\Xpsr\Scheduler\SchedulerServiceRegistry::class,
Crystoline\Xpsr\Storage\StorageServiceRegistry::class,
Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry::class,
Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry::class,   // right after FactoryRegistry
```

Configuration lives in `config/*.php` files that read `.env` through `env()`, as in Laravel, but each package has its own options; its README shows them.

### 2. The environment

- **Keys:** copy `APP_KEY`, and `APP_PREVIOUS_KEYS` if it is set. Keep `app.cipher` as Laravel's (`AES-256-CBC` unless it was changed).
- **Time zone:** keep `timezone` in `config/app.php` the same as Laravel's. Sanctum's and the notifications' times are stored in it, as Laravel stores them.
- **Database:** `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` have the same names. XPSR's connections are `sqlite`, `mysql`, and `pgsql`; for Laravel's `mariadb` connection, use `mysql`.
- **Services by DSN:** where Laravel has several variables per service, XPSR takes one DSN: `MAIL_DSN=smtp://user:secret@smtp.example.com:587`, `QUEUE_DSN=redis://127.0.0.1:6379/messages`, `SMS_DSN=termii://API_KEY@default?from=MPAY&channel=generic`, and `LOCK_STORE=redis://127.0.0.1:6379`.
- **Web push:** copy `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`, so subscribed browsers keep getting pushes.

Three of Laravel's tables have names XPSR uses for tables of its own, laid out differently. Give XPSR's other names:

| Laravel's table | XPSR option | For example |
|---|---|---|
| `migrations` | `migrations.table` in `config/database.php` | `xpsr_migrations` |
| `failed_jobs` | `failed.table` in `config/queue.php` | `xpsr_failed_jobs` |
| `sessions` (database sessions only) | `database.table` in `config/session.php` | `xpsr_sessions` |

The migration commands stop with a message if `migrations.table` names Laravel's table.

### 3. The database

With `.env` pointing at the Laravel database, or at a copy of it:

```sh
php xpsr migrate:baseline --exclude=cache --exclude=cache_locks --exclude=jobs --exclude=job_batches
```

The baseline describes every table, so a new database, such as the one tests use, gets the same schema. Tables named with `--exclude` stay in the database but are left out of the baseline. On staging and production, the same command only records the baseline. Run it before any other XPSR migration; then create XPSR's own tables, such as `php xpsr make:queue-table`, and run `php xpsr migrate`.

From then on, change the schema with XPSR migrations (`php xpsr make:migration`), written with DBAL's schema API. Eloquent's schema builder and Laravel's migration files are not used.

### 4. Models

Copy `app/Models`. Models keep their casts, relationships, scopes, accessors, events, and soft deletes, and encrypted casts use XPSR's encrypter. Remove Laravel's traits that need the rest of Laravel, such as `HasApiTokens`, `Notifiable`, and `HasFactory`, and implement XPSR's interfaces instead:

```php
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Notifications\{NotifiableInterface, NotifiableKey};
use Crystoline\Xpsr\Orm\Eloquent\Auth\Authenticatable;

class User extends Model implements UserInterface, NotifiableInterface
{
    use Authenticatable;

    public function notificationAddress(string $channel): ?string
    {
        return match ($channel) {
            'mail' => $this->email,
            'sms' => $this->phone,
            default => null,
        };
    }

    public function notifiableKey(): NotifiableKey
    {
        return new NotifiableKey(self::class, $this->id);   // Laravel's notifiable_type: the class, or its morph map alias
    }
}
```

`paginate()` needs `illuminate/pagination`; `crystoline/xpsr-api` pages Eloquent queries without it, with the same `data`, `links`, and `meta` (see [Routes and controllers](#6-routes-and-controllers)). Model factories need `fakerphp/faker`.

### 5. Authentication

In `config/auth.php`, a guard with the `sanctum` driver for each kind of user, and the users from the Eloquent models:

```php
'guards' => [
    'api' => ['driver' => 'sanctum', 'provider' => 'users'],
    'admin_api' => ['driver' => 'sanctum', 'provider' => 'admins'],
],
'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class, 'login' => 'email'],
    'admins' => ['driver' => 'eloquent', 'model' => App\Models\Admin::class, 'login' => 'email'],
],
```

Each guard accepts only the tokens whose `tokenable_type` is its model, so an admin's token never counts on the customers' API. Routes use `Authenticate::guard('api')` where Laravel had `auth:sanctum`, and `RequireScopes::of('payments:create')` where it checked `tokenCan()`. Policies are listed in `config/auth.php` and checked with `Crystoline\Xpsr\Auth\Access\Gate`. See [Authentication](AUTH.md).

Registration, password reset, email verification, and two-factor sign-in stay application code, as they may already be.

### 6. Routes and controllers

```php
// routes/api.php
return static function (RouteCollection $routes): void {
    $routes->group('/api', static function (RouteCollection $routes): void {
        $routes->get('/payments/{reference}', [PaymentController::class, 'show']);
        $routes->post('/payments', [PaymentController::class, 'store'])->middleware(RequireScopes::of('payments:create'));
    }, [Authenticate::guard('api'), RateLimit::named('api')]);
};
```

```php
final class PaymentController
{
    public function __construct(
        private readonly ResponseBuilder $responses,
        private readonly ValidatorInterface $validator,
        private readonly Gate $gate,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $payment = Payment::query()->where('reference', $request->getAttribute('reference'))->first()
            ?? throw new NotFoundHttpException('No such payment.');
        $this->gate->authorize(Identity::of($request)->user, 'view', $payment);

        return $this->responses->json($payment->toArray());
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->validate($request, CreatePayment::class);   // 422 with "message" and "errors" by field, as Laravel
        // ...
    }
}
```

- **A controller method takes the request** and returns a response. Its services come through the constructor. Route parameters are request attributes; there is no route model binding.
- **Form requests become input classes** (`php xpsr make:input CreatePayment`): constructor parameters with Symfony Validator attributes. Authorization moves to the gate.
- **Middleware** is PSR-15 (`php xpsr make:middleware`). `throttle:api` becomes `RateLimit::named('api')`, with the limits in `config/rate_limit.php`.
- **Errors:** throw an exception from `Crystoline\Xpsr\Http\Exception`, such as `NotFoundHttpException`, where Laravel calls `abort()`. JSON clients get `{"message": ...}`.
- **API resources** are `Crystoline\Xpsr\Api\Resource` from `crystoline/xpsr-api`: the same `toArray($request)`, `when()`, `whenLoaded()`, `mergeWhen()`, `collection()`, and `data` envelope, returned from the handler as before. `paginate()` becomes `$this->pagination->fromRequest($request)->paginate(Payment::query())`, with Laravel's `data`, `links`, and `meta`. See [its README](../packages/api/README.md#coming-from-laravel).

See [Routing](ROUTING.md) and [HTTP](HTTP.md).

### 7. Jobs, mail, notifications, events, and the schedule

- **Jobs** implement `Crystoline\Xpsr\Queue\JobInterface`. Their `handle()` methods take services by type, as Laravel's do, and so do their `failed()` methods, after the exception. `ShouldBeUnique` becomes `#[Unique]`, `onQueue()` becomes `#[OnQueue]` or a `queue:` argument, `$tries` and `$backoff` become `#[Retry]`, and `afterCommit()` or `ShouldDispatchAfterCommit` becomes `#[AfterCommit]`. Keep a job's data to IDs and plain values: XPSR does not reload models the way `SerializesModels` does.
- **Mailables** become Symfony `Email` messages, usually from a view: `$mailer->compose('emails.receipt', [...])`.
- **Notifications** implement one interface per channel instead of `via()`. See [the notifications package](../packages/notifications/README.md#coming-from-laravel).
- **Events** are PSR-14: dispatch with `EventDispatcherInterface`, and list listeners in `config/events.php`. Listeners run at once; one that should run later dispatches a job.
- **The schedule** moves to `routes/schedule.php`, and cron runs `php xpsr schedule:run`. `everyTenSeconds()` and the other sub-minute frequencies become `everySeconds(10)`.

### 8. Tests

Tests extend the skeleton's `tests/TestCase.php`, which has `get()`, `getJson()`, `postJson()`, `putJson()`, `patchJson()`, `deleteJson()`, `request()`, `actingAs()`, `withHeaders()`, `withToken()`, `console()`, `swap()`, and `freezeTime()`. Responses have the assertions of Laravel's: `assertStatus()`, `assertOk()`, `assertCreated()`, `assertNoContent()`, `assertNotFound()`, `assertUnauthorized()`, `assertForbidden()`, `assertUnprocessable()`, `assertRedirect()`, `assertHeader()`, `assertJson()`, `assertExactJson()`, `assertJsonPath()`, `assertJsonMissingPath()`, `assertJsonCount()`, `assertJsonValidationErrors()`, and `assertSee()`. See [Testing](TESTING.md).

| Laravel | XPSR |
|---|---|
| `RefreshDatabase` | `RefreshDatabase` from the database package: the same, including on an in-memory SQLite database |
| `DatabaseTruncation` | `DatabaseTruncation` |
| `assertDatabaseHas()`, `assertDatabaseMissing()`, `assertDatabaseCount()`, `assertDatabaseEmpty()` | The same, from the `DatabaseAssertions` trait |
| `assertJsonStructure()` | `assertJson()` with the keys, or `assertJsonPath()` for each |
| `Queue::fake()`, `Queue::assertPushed()` | `$this->swap(QueueInterface::class, $queue = new FakeQueue())`, then `$queue->assertDispatched()` |
| `Notification::fake()`, `Notification::assertSentTo()` | `$this->swap(NotifierInterface::class, $notifier = $this->application()->get(FakeNotifier::class))`, then `$notifier->assertSent($class, $user)` |
| `Mail::fake()` | `MAIL_DSN=array://default`, then the mail package's `ArrayTransport` |
| `Storage::fake('s3')` | `$this->application()->get(StorageManager::class)->fake('s3')` |
| `Http::fake()`, `Http::assertSent()` | `$this->swap(ClientInterface::class, $http = new FakeClient(...))`, then `$http->assertRequested()` |
| `Carbon::setTestNow()` | `freezeTime()` for XPSR's clock; Eloquent's timestamps use Carbon, so set both when a test depends on them |

## Laravel to XPSR

| Laravel | XPSR |
|---|---|
| Facades, `app()`, `resolve()` | Constructor parameters, by type. `app()->get(...)` and `config()` exist for the rare place without injection. |
| `Route::get()` | `$routes->get()` in `routes/*.php` |
| `$request->input('amount')` | An input class, or `$request->getParsedBody()['amount']` |
| `response()->json()` | `ResponseBuilder::json()` |
| `JsonResource`, `PaymentResource::collection()` | `Crystoline\Xpsr\Api\Resource`, `PaymentResource::collection()` |
| `Payment::query()->paginate()` | `Pagination::fromRequest($request)->paginate(Payment::query())` |
| `abort(404)` | `throw new NotFoundHttpException()` |
| `$request->user()`, `auth()->user()` | `Identity::of($request)->user` |
| `Auth::attempt()` | `AuthManager::attempt()` |
| `Hash::make()`, `Hash::check()` | `PasswordHasher::hash()`, `verify()` |
| `Gate::authorize()`, `$this->authorize()` | `Gate::authorize()` |
| `$user->createToken()` | `SanctumTokenRepository::create()` |
| `DB::table()`, `DB::select()` | Doctrine DBAL's `Connection`, or Eloquent |
| `DB::afterCommit()` | `Transactions::afterCommit()` |
| `DB::listen()`, `DB::whenQueryingForLongerThan()` | The `QueryExecuted` event in `config/events.php`, or `slow_queries` in `config/database.php` |
| `Crypt::encryptString()` | `Encrypter::encryptString()` |
| `Cache::remember()` | PSR-16 `CacheInterface`: `get()`, then `set()` |
| `Cache::lock()` | `Locks::run()` |
| `RateLimiter::attempt()`, `hit()`, `clear()` | `RateLimits::attempt()`, `hit()`, `reset()` |
| `dispatch()`, `Job::dispatch()` | `QueueInterface::dispatch()` |
| `Mail::to()->send()` | `Mailer::send()`, `Mailer::queue()` |
| `$user->notify()` | `NotifierInterface::send()`, `queue()` |
| `event()` | `EventDispatcherInterface::dispatch()` |
| `Storage::disk()` | `StorageManager::disk()` |
| `Http::get()` | PSR-18 `ClientInterface`, and named clients |
| `Log::info()` | PSR-3 `LoggerInterface` |
| `php artisan ...` | `php xpsr ...`: `migrate`, `queue:work`, `queue:retry`, `schedule:run`, `make:*` |

## Running both side by side

An application can move a route group or a job at a time, with Laravel and XPSR on the same database:

- **Shared:** the database, `APP_KEY`, the time zone, Sanctum tokens (each accepts the other's), encrypted columns, and the notifications and push subscriptions tables.
- **Passwords.** XPSR stores a new Argon2id hash when a user with a bcrypt hash signs in. Laravel's bcrypt hasher refuses a hash that is not bcrypt while `HASH_VERIFY` is on, as it is by default in Laravel 12, and the sign-in fails with an error. Set `HASH_VERIFY=false` in Laravel's `.env` while both run: Laravel then checks both kinds, and may store a bcrypt hash again when the user signs in there, which XPSR also accepts.
- **Schema changes** happen in XPSR's migrations once it has its baseline, so each side keeps its own migrations table.
- **Queues.** Give each side its own queue: XPSR's workers cannot run Laravel's jobs, nor Laravel's XPSR's.
- **The schedule.** Run each task on one side only.
- **Locks and rate limits are not shared.** A lock Laravel holds does not stop XPSR, and the reverse. Move all the code that must not run twice at once, such as capturing a payment, in one step.

## Not in XPSR

- Facades, route model binding, form request `authorize()`, and service injection into controller methods.
- Broadcasting, Horizon, Telescope, Pulse, Socialite, Passport, Cashier, and Scout.
- Octane: long-running servers have not been verified (see [Deployment](DEPLOYMENT.md#not-provided-yet)).
- Registration, password reset, email verification, two-factor sign-in, and "remember me" cookies.
- Translations: validation messages are in English.
- Tinker, and route and configuration caches, which XPSR does not need (see [Deployment](DEPLOYMENT.md#performance)).
