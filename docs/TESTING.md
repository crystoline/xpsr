# Testing

An application's tests send requests through the HTTP kernel and check the responses, run console commands, sign users in, replace the queue, notifications, and outgoing HTTP with fakes, and start every test from a known database. This page lists what the test case provides. [Getting started](GETTING_STARTED.md#5-write-tests) shows a first test.

## The test case

`tests/TestCase.php` extends `Crystoline\Xpsr\Testing\ApplicationTestCase` and says where the application comes from:

```php
abstract class TestCase extends ApplicationTestCase
{
    protected function createApplication(): Application
    {
        return require dirname(__DIR__) . '/bootstrap/app.php';
    }
}
```

Each test gets a fresh application. `phpunit.xml` sets `APP_ENV=testing`, so `config/testing/*.php` overrides apply (an in-memory database, a known API key), and `SESSION_STORE=array`, so sessions leave no files. Helpers that your tests share belong in `TestCase`; the ones below come with the framework, so they improve with it.

## Requests

```php
$this->get('/tasks');
$this->getJson('/api/tasks?page=2');
$this->postJson('/api/tasks', ['title' => 'Ship it']);
$this->putJson('/api/tasks/1', ['done' => true]);
$this->patchJson('/api/tasks/1', ['title' => 'Shipped']);
$this->deleteJson('/api/tasks/1');
$this->request('POST', '/upload', ['Content-Type' => 'text/csv'], "a,b\n1,2");
$this->postForm('/profile', ['name' => 'Ada']);      // a browser form, with the session's CSRF token
```

Requests go through the full HTTP kernel: global middleware, routing, and error rendering, so a missing route gives the same 404 as in production. The `*Json()` methods send `$data` as JSON and ask for JSON back (`Accept: application/json`), so errors come back as JSON too; `getJson()` and `deleteJson()` send a body only when given data. `request()` takes any method, headers, and a body: an array is sent as JSON, a string as it is.

- **Headers for every request:** `$this->withHeaders(['X-Api-Key' => 'test-key'])`, and `$this->withToken($token)` for `Authorization: Bearer <token>`. A request's own headers win.
- **Signed in:** `$this->actingAs($user)` authenticates every later request as `$user` on every guard, or `actingAs($user, 'api', ['payments:create'])` on one guard with these scopes, without a sign-in request. See [Authentication](AUTH.md).
- **Sessions:** cookies that responses set are sent with later requests, so a test behaves like one browser. `withSession(['cart' => [...]])` stores values in that session, `session()->get('cart')` reads them back, and `csrfToken()` is the token forms need.

## Responses

Every request returns a `Crystoline\Xpsr\Testing\TestResponse`: the PSR-7 response, with assertions that chain. A failed assertion shows the status and the start of the body.

```php
$this->postJson('/api/payments', ['amount' => 5000, 'currency' => 'NGN'])
    ->assertCreated()
    ->assertJsonPath('data.status', 'pending')
    ->assertJson(['data' => ['currency' => 'NGN']])
    ->assertHeader('Location');
```

| Assertion | Passes when |
|---|---|
| `assertStatus(201)`, `assertOk()`, `assertCreated()`, `assertNoContent()`, `assertNotFound()`, `assertUnauthorized()`, `assertForbidden()`, `assertUnprocessable()` | The status is the one named (`assertNoContent()` also wants an empty body). |
| `assertRedirect()`, `assertRedirect('/login')` | A 3xx with a `Location` header, to that path or URL when given. |
| `assertHeader('Cache-Control')`, `assertHeader('Content-Type', 'application/json')`, `assertHeaderMissing('Set-Cookie')` | The header is present, with that value; or absent. |
| `assertJson(['data' => ['id' => 7]])` | The JSON body has each key given, with that value; nested arrays are checked the same way, and other keys are allowed. |
| `assertExactJson([...])` | The whole JSON body is this. |
| `assertJsonPath('data.0.id', 7)`, `assertJsonPath('data', fn(array $data): bool => count($data) > 1)` | The value at a dot path is this, or passes the closure. |
| `assertJsonMissingPath('data.password')` | There is nothing at the path. |
| `assertJsonCount(2)`, `assertJsonCount(2, 'data')` | The JSON body, or the array at the path, has this many items. |
| `assertJsonValidationErrors('title')`, `assertJsonValidationErrors(['title', 'amount' => 'positive'])` | A 422 whose `errors` name each field, with a message containing the text when one is given. |
| `assertSee('Welcome')`, `assertDontSee('Error')` | The body contains, or does not contain, the text: for HTML pages. |

`$response->json()` is the decoded body, `json('data.0.id')` a value in it, `body()` the body as a string, `status()` the status, and `response()` the PSR-7 response. `getStatusCode()`, `getHeaderLine()`, and `getBody()` work as before.

## Console commands

```php
[$status, $output] = $this->console('app:task-count');
[$status, $output] = $this->console('app:greet', ['name' => 'Ada', '--loud' => true]);
```

Arguments and options go by name. Commands run through the console kernel, without a process.

## Time and services

- `freezeTime('2026-01-31 09:00:00')` stops the application clock (`Psr\Clock\ClockInterface`) and returns a `MockClock` to move with `modify('+1 hour')` or `sleep(60)`. Eloquent's timestamps use Carbon, so set `Carbon::setTestNow()` as well when a test depends on them. See [the clock](CONTAINER.md#the-clock).
- `swap($id, $replacement)` replaces a service for the rest of the test, for example a fake in place of `Psr\Http\Client\ClientInterface`. Services built before the swap keep the original, so swap early in the test.

## Fakes

Each fake keeps what the application would have sent, and has assertions about it.

| Fake | Replaces | Assertions |
|---|---|---|
| `Crystoline\Xpsr\Queue\Testing\FakeQueue` | `QueueInterface`: `swap(QueueInterface::class, $queue = new FakeQueue())` | `assertDispatched(SendReceipt::class)`, `assertDispatched(SendReceipt::class, 2)`, `assertDispatched(SendReceipt::class, fn(SendReceipt $job): bool => $job->orderId === 7)`, `assertNotDispatched(...)`, `assertNothingDispatched()`. `dispatched()` lists the jobs, and `run($runner)` runs them when a test needs their effects. |
| `Crystoline\Xpsr\Notifications\Testing\FakeNotifier` | `NotifierInterface`: `swap(NotifierInterface::class, $notifier = $this->application()->get(FakeNotifier::class))` | `assertSent(PaymentReceived::class)`, `assertSent(PaymentReceived::class, $user)`, `assertSent(PaymentReceived::class, $user, 1)`, `assertNotSent(...)`, `assertNothingSent()`. `sent()` and `sentTo($user)` give each notification with the message made for each channel. |
| `Crystoline\Xpsr\HttpClient\Testing\FakeClient` | `Psr\Http\Client\ClientInterface`: `swap(ClientInterface::class, $http = new FakeClient($response, ...))` | `assertRequested('POST https://api.gateway.test/charges')`, `assertRequested(fn(RequestInterface $request): bool => ..., 2)`, `assertNotRequested(...)`, `assertNothingRequested()`. `requests()` lists the requests sent. |
| `StorageManager::fake('s3')` | A disk, with files in memory | The disk's own methods, such as `exists()`. |
| `MAIL_DSN=array://default` | Sending email | The mail package's `ArrayTransport` keeps the messages. |

Fakes are built by the test, so `swap()` them before the request that would use the real service.

## The database

With [`crystoline/xpsr-database`](../packages/database/README.md#testing), a test case gets three traits. Each needs the test case's `application()` method, which `ApplicationTestCase` has.

**`DatabaseAssertions`** checks tables:

```php
$this->assertDatabaseHas('payments', ['reference' => 'PAY-1', 'status' => 'paid']);
$this->assertDatabaseMissing('payments', ['reference' => 'PAY-2']);
$this->assertDatabaseCount('payments', 3);
$this->assertDatabaseEmpty('refunds');
```

A null value matches `NULL`, and a third argument names a connection other than the default. A failed `assertDatabaseHas()` shows the rows that match the first column, so the difference is visible.

**`RefreshDatabase`** gives every test a migrated, empty database. Before the first test of the process, it drops every table on the connection and runs the migrations; then it wraps each test in a transaction that is rolled back afterwards, including rows seeded in `setUp()`. On an in-memory SQLite database, which is new for every test, it migrates each time. It refuses to run when `app.env` is `production`.

```php
final class PaymentTest extends TestCase
{
    use RefreshDatabase;
    use DatabaseAssertions;

    protected function setUp(): void
    {
        $this->seedPlans();   // inside the test's transaction
    }
}
```

Override `connectionsToRefresh()` to return the names of other connections to refresh as well.

**`DatabaseTruncation`** is for tests whose data must be visible to other connections or processes, such as a test that runs two workers against one row to prove a lock: the test's changes are committed, and every table except the migrations table is emptied after the test (after `tearDown()`). Override `tablesToKeep()` for tables to leave alone, such as reference data. Truncation is slower than a rollback, so use it only where the rollback does not work.

**`DatabaseTransactions`** is the rollback alone, for a database that is already migrated: call `$this->beginDatabaseTransaction()` in `setUp()`. The test's transaction does not count for [`Transactions::afterCommit()`](../packages/database/README.md#after-a-transaction-commits): callbacks and `#[AfterCommit]` jobs run when the code under test commits its own outermost transaction.

The database comes from `config/testing/database.php`:

```php
return [
    'default' => 'testing',
    'connections' => ['testing' => ['driver' => 'pdo_sqlite', 'memory' => true]],
];
```

SQLite differs from MySQL and PostgreSQL in places, such as type checking and some SQL functions. Point the connection at a real test database to run the suite against your production database type as well; `RefreshDatabase` then migrates it once per run.

## Coming from Laravel

The assertions keep Laravel's names where they do the same thing; [Coming from Laravel](FROM_LARAVEL.md#8-tests) maps the rest.
