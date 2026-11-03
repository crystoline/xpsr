# crystoline/xpsr-broadcast

Live updates to browsers for XPSR applications, through a [Mercure](https://mercure.rocks) hub. The application publishes an update to a topic; every browser subscribed to that topic receives it at once, over a plain `EventSource`. No WebSocket server, no long-running PHP process: the hub is one binary, a Docker image, or [FrankenPHP](https://frankenphp.dev)'s built-in one, and the application talks to it over HTTP.

```sh
composer require crystoline/xpsr-broadcast
```

Add the provider to `app.registry` in `config/app.php`, after the queue, database, auth, and view providers, and the middleware to `app.middleware`:

```php
'registry' => [
    // ...
    Crystoline\Xpsr\Broadcast\BroadcastServiceRegistry::class,
],
'middleware' => [
    // ...
    Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware::class,
],
```

Then the hub's URL and the key it verifies tokens with, in `.env`:

```sh
BROADCAST_HUB_URL=http://localhost:3000/.well-known/mercure
BROADCAST_SECRET='!ChangeThisMercureHubJWTSecretKey!'
```

## The hub

For development, the hub's Docker image, with the same key and the origin browsers load pages from:

```sh
docker run -d -p 3000:80 -e SERVER_NAME=':80' \
  -e MERCURE_PUBLISHER_JWT_KEY='!ChangeThisMercureHubJWTSecretKey!' \
  -e MERCURE_SUBSCRIBER_JWT_KEY='!ChangeThisMercureHubJWTSecretKey!' \
  -e MERCURE_EXTRA_DIRECTIVES='cors_origins http://localhost:8000' \
  dunglas/mercure
```

In production the hub runs behind HTTPS, with a key of its own (32 random bytes at least). `BROADCAST_HUB_URL` is where the application publishes, on the private network; `BROADCAST_PUBLIC_HUB_URL` is where browsers subscribe when that differs, such as `https://hub.example.com/.well-known/mercure`. The hub's `cors_origins` must name the application's origin, since browsers subscribe from its pages.

## Publishing

An update goes to one topic or several, with any data; arrays become JSON:

```php
broadcast('orders/42', ['status' => 'paid']);
broadcast(['orders/42', 'users/7/orders'], ['status' => 'paid'], private: true, type: 'order.paid');

// or the service, with the update's ID and the browsers' reconnection delay too
$broadcast->publish('orders/42', ['status' => 'paid']);
$broadcast->publishUpdate(new Update('orders/42', ['status' => 'paid'], private: true, type: 'order.paid', id: 'urn:uuid:...', retry: 5000));
```

Topics are strings the application chooses, such as `orders/42`; Mercure calls them topics and lets a subscriber ask for several. The type is the event name browsers listen for; without one they get `message` events. A private update reaches only subscribers authorized for one of its topics, below.

**Events.** An event that implements `BroadcastableInterface` is published when it is dispatched, so the code that dispatches it does not know about browsers:

```php
final class OrderPaid implements BroadcastableInterface
{
    public function __construct(public readonly int $orderId, public readonly int $userId) {}

    public function toUpdate(): Update
    {
        return new Update("users/{$this->userId}/orders", ['order' => $this->orderId, 'status' => 'paid'], private: true, type: 'order.paid');
    }
}

$this->events->dispatch(new OrderPaid($order->id, $order->userId));
```

**Transactions.** An update published inside a database transaction leaves once the transaction commits, and never if it rolls back, so a browser never hears of a row that was undone. With [`crystoline/xpsr-queue`](../queue/README.md), each update is a `PublishUpdate` job (`#[AfterCommit]`), tried again after 2, 10, and 50 seconds when the hub does not take it; `broadcast.queue` names the transport, or `false` publishes in the request. Without the queue, [`crystoline/xpsr-database`](../database/README.md)'s `Transactions::afterCommit()` holds the update until the commit; without both, it leaves at once.

**Failures.** A hub that cannot be reached, or refuses an update, throws `HubException` with its answer: from `publish()`, from the commit when the update waited for one, or, through the queue, after the job's retries, when it is recorded as failed. Catch it where losing an update is acceptable.

## Subscribing

A page subscribes with `EventSource` at the hub's URL for its topics. `hub_url()` gives that URL, in templates and code, and sets the signed-in user's authorization cookie on the response (see below):

```twig
<div id="order" data-hub="{{ hub_url(['orders/' ~ order.id]) }}"></div>

<script>
const source = new EventSource(document.getElementById('order').dataset.hub);
source.addEventListener('order.paid', (event) => {
    const update = JSON.parse(event.data);
    ...
});
source.onmessage = (event) => { ... };   // updates without a type
</script>
```

Put the URL in an attribute rather than a script string: the engine escapes it for HTML, and `&` between topics would break a JavaScript string. `hub_url(topics, false)` gives the URL without touching the cookie, for public topics. A browser that reconnects after losing its connection sends the ID of the last update it saw, and the hub replays what it missed; `$hub->url($topics, $lastEventId)` does the same for a page that stores it.

## Private topics

A private update needs a subscriber the hub trusts. `broadcast.subscriptions` in `config/broadcast.php` says which topics each signed-in user may receive, as **topic selectors**: exact topics, [URI templates](https://mercure.rocks/spec#topic-selectors) such as `users/7/{+rest}` (every topic under `users/7/`), or `*` for everything:

```php
use Crystoline\Xpsr\Auth\UserInterface;

return [
    'subscriptions' => static fn(UserInterface $user): array => ["users/{$user->authIdentifier()}/{+rest}", 'announcements'],
];
```

A list instead of a closure gives every signed-in user the same selectors. A closure that accepts `?UserInterface` is asked about guests too; one typed `UserInterface` is not, and guests receive nothing private.

The authorization travels in a cookie the hub reads, `mercureAuthorization` by default: a signed token listing the selectors, `HttpOnly`, `Secure` when the hub is https, `SameSite=Strict`, sent to the hub's path alone, for `broadcast.ttl` (an hour). `hub_url()` sets it for the signed-in user on every page that calls it, through `SubscriptionsMiddleware`; a page that opens its subscription from script, or needs the cookie renewed before it expires, can call an endpoint instead:

```php
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Broadcast\Http\SubscriptionsController;

$routes->get('/broadcast/authorize', [SubscriptionsController::class, 'authorize'])->middleware(Authenticate::optional());
```

It answers `{"hub": "...", "selectors": [...]}` with the cookie; for a guest, or a user with nothing to subscribe to, it removes the cookie. In code, `Subscriptions` does the same: `authorize($response, $user)`, `forget($response)` for a sign-out handler, and `token($user)` for a mobile application that sends `Authorization: Bearer` to the hub instead of a cookie.

When the hub is on another subdomain, such as `hub.example.com` beside `www.example.com`, set `broadcast.cookie_domain` to `example.com` so the browser sends the cookie there.

## Testing

`Crystoline\Xpsr\Broadcast\Testing\FakeBroadcast` keeps the updates the application would have published:

```php
use Crystoline\Xpsr\Broadcast\BroadcastInterface;
use Crystoline\Xpsr\Broadcast\Testing\FakeBroadcast;
use Crystoline\Xpsr\Broadcast\Update;

$this->swap(BroadcastInterface::class, $broadcast = new FakeBroadcast());
$this->postJson('/orders/42/pay');
$broadcast->assertPublished('orders/42', fn(Update $update): bool => $update->private && $update->json()['status'] === 'paid');
$broadcast->assertNotPublished('orders/43');
$broadcast->assertNothingPublished();
```

`assertPublished($topic, $check, $times)` returns the first matching update; `published($topic)` lists them. Updates published through the queue reach the fake when the job runs, so in a test of a queued publish, use the fake queue or `sync://`. The `null` driver (`BROADCAST_DRIVER=null`) drops every update, for an environment without a hub.

## Command

```sh
php xpsr broadcast:publish orders/42 '{"status":"paid"}' --private --type=order.paid
```

Publishes one update now, to try a hub or a page from the shell, and prints the ID the hub gave it; exit code 1 when the hub refuses.

## Configuration

`config/broadcast.php` is optional; the environment variables cover the usual case:

```php
return [
    'hub' => env('BROADCAST_HUB_URL'),              // where the application publishes
    'public_hub' => env('BROADCAST_PUBLIC_HUB_URL'), // where browsers subscribe (default: hub)
    'secret' => env('BROADCAST_SECRET'),            // the hub's key; "base64:..." is decoded
    'publisher_secret' => null,                     // when the hub has two keys (default: secret)
    'subscriber_secret' => null,
    'algorithm' => 'HS256',                         // or HS384, HS512, as the hub is configured
    'driver' => env('BROADCAST_DRIVER', 'mercure'), // or null, to drop updates
    'queue' => true,                                // publish from a queue job; a transport name; false
    'client' => null,                               // the http_client.clients entry to publish with
    'subscriptions' => [],                          // the selectors of signed-in users, or a closure
    'ttl' => '1 hour',                              // how long an authorization lasts; null for the browser session
    'cookie' => 'mercureAuthorization',             // the cookie's name, as the hub expects it
    'cookie_domain' => null,                        // when the hub is on another subdomain
];
```

Invalid values fail when broadcasting is first used, with the setting's name. The secret must be at least 32 bytes for HS256, 48 for HS384, 64 for HS512.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Broadcast\BroadcastInterface` | `publish()`, `publishUpdate()`: through the queue or after the commit. Shared; `Broadcast` is the same service. |
| `Crystoline\Xpsr\Broadcast\Hub` | The hub: `publish()` now, `url()` to subscribe at. Shared. |
| `Crystoline\Xpsr\Broadcast\Subscriptions` | `selectors()`, `authorize()`, `forget()`, `token()`, `hubUrl()`. Shared. |
| `Crystoline\Xpsr\Broadcast\Http\SubscriptionsMiddleware` | Adds the cookie `hub_url()` asked for. |
| `Crystoline\Xpsr\Broadcast\Http\SubscriptionsController` | `authorize()`, for a route. |

Not provided: WebSocket servers and the Pusher protocol (Soketi, Laravel Echo), presence channels (who is online), messages from the browser to the server, which HTTP requests already are, and RS256 keys, since the application and its hub share a secret.

Requires PHP 8.4+, `crystoline/xpsr-http-client`, and `lcobucci/jwt` 5.6+. The queue and database packages are detected; the auth provider for the signed-in user; the view provider for `hub_url()` in templates.
