# crystoline/xpsr-webhooks

Webhooks for XPSR applications. **Sending:** subscribers register endpoints, the application sends an event, and each subscribed endpoint gets a signed JSON POST through the queue, with retries, a delivery log, and events for what happened. **Receiving:** a middleware verifies the webhooks providers such as Stripe, Paystack, or GitHub send, with their own signing schemes.

```sh
composer require crystoline/xpsr-webhooks
```

The package needs the database, queue, and HTTP client providers. Add its provider after them in `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Webhooks\WebhookServiceRegistry::class,
```

Create and run the migration for the two tables, then set `APP_KEY` if it is not set yet (endpoint secrets are stored encrypted with it):

```sh
php xpsr make:webhooks-table
php xpsr migrate
```

`config/webhooks.php` is optional for sending; receiving needs its `inbound` entries:

```php
return [
    'client' => null,                 // a client of config/http_client.php, or null for the default
    'header_prefix' => 'X-Webhook',   // X-Webhook-Id, -Event, -Timestamp, -Signature
    'tolerance' => '5 minutes',       // how old a signed timestamp may be when verifying
    'queue' => null,                  // the queue deliveries go on
    'allow_private_urls' => false,    // true only in development
    'inbound' => [
        'stripe' => ['scheme' => 'stripe', 'secret' => env('STRIPE_WEBHOOK_SECRET')],
        'paystack' => ['scheme' => 'hmac', 'header' => 'x-paystack-signature', 'algorithm' => 'sha512', 'secret' => env('PAYSTACK_SECRET_KEY')],
    ],
];
```

## Sending

### Endpoints

`Crystoline\Xpsr\Webhooks\Endpoints` keeps the subscribers. `add()` takes the URL and the events wanted, generates a secret, and returns the endpoint with the secret in clear, once; show it to the subscriber then, as it is stored encrypted and `find()` returns it only to the application.

```php
$endpoint = $this->endpoints->add('https://shop.example.com/hooks/xpsr', ['payment.*', 'refund.issued'], 'Shop');
$endpoint->id;      // a UUID
$endpoint->secret;  // whsec_..., for the subscriber to verify deliveries with
```

Events are names such as `payment.succeeded`; `payment.*` subscribes to a family and `*` to everything. `find()`, `all()`, `for($event)` (the enabled subscribers of an event), `disable()`, `enable()`, `remove()`, and `rotateSecret()` manage them.

An endpoint URL must be http or https with a host that does not resolve to a loopback, private, link-local, or unspecified address, so a subscriber cannot point the application at its own network, such as a cloud metadata service. The check runs when the endpoint is added and again before each delivery. `webhooks.allow_private_urls` turns the address check off for development.

### Sending an event

```php
use Crystoline\Xpsr\Webhooks\WebhooksInterface;

public function __construct(private readonly WebhooksInterface $webhooks) {}

$deliveries = $this->webhooks->send('payment.succeeded', ['id' => $payment->id, 'amount' => $payment->amount, 'currency' => 'NGN']);
```

`send()` records one delivery per subscribed endpoint and queues a `DeliverWebhook` job for each; it returns the deliveries, pending. The job leaves only once the database transaction open when `send()` was called, if any, has committed, so a webhook for a payment never goes out before the payment is recorded. `sendTo($endpoint, $event, $data)` sends to one endpoint whether or not it subscribes.

Each delivery is a POST with `Content-Type: application/json`, the headers `X-Webhook-Id` (the delivery's UUID), `X-Webhook-Event`, `X-Webhook-Timestamp`, and `X-Webhook-Signature`, and this body:

```json
{"id": "019a2c6e-...", "event": "payment.succeeded", "created_at": "2026-11-02T10:00:00+00:00", "data": {"id": 7, "amount": 125000, "currency": "NGN"}}
```

The signature is `t=<timestamp>,v1=<hex HMAC-SHA256 of "<timestamp>.<body>">` with the endpoint's secret, the form Stripe made familiar. A subscriber checks that the timestamp is recent and the digest matches; `Crystoline\Xpsr\Webhooks\Signature::verify($secret, $header, $body, time(), 300)` does it in PHP, and a subscriber that runs XPSR uses the `xpsr` inbound scheme below.

### What happens to a delivery

| The endpoint answers | Result |
|---|---|
| 2xx | Delivered. `WebhookDelivered` is dispatched. |
| 410 Gone | The endpoint is disabled and the delivery fails; `WebhookFailed` is dispatched. |
| Anything else, or no answer | The attempt is recorded and the queue tries again after 1, 4, 16, and 64 minutes and then 6 hours. After the last try, the delivery fails and `WebhookFailed` is dispatched. |

The retry schedule is the `#[Retry]` of the `DeliverWebhook` job, the same on every transport. On the `sync://` transport there are no retries: the first failed attempt is the last, and `send()` throws `DeliveryFailedException`, so use a real transport where endpoints may be down.

The events are `Crystoline\Xpsr\Webhooks\Event\WebhookDelivered` (the delivery and the endpoint) and `WebhookFailed` (the delivery, the endpoint if it still exists, and the error), dispatched through PSR-14 when the event dispatcher is bound. Every failed attempt is also logged as a warning, and a failed delivery as an error.

### The delivery log

`Crystoline\Xpsr\Webhooks\Deliveries` is the log: `find($id)` and `forEndpoint($endpointId)` give `Delivery` objects with the event, payload, attempts, status (`pending`, `delivered`, or `failed`), the last HTTP status and error, and when they were created, delivered, or failed. The ID is what the subscriber saw in `X-Webhook-Id`, so support can answer "did you send it?".

- `$webhooks->resend($deliveryId)` or `php xpsr webhooks:resend <id>` queues a delivery again, with the same payload.
- `php xpsr webhooks:prune --days=30` removes delivered and failed deliveries older than that; schedule it.

## Receiving

Name each provider in `webhooks.inbound` with how it signs, and put `VerifyWebhook::named()` on the route:

```php
use Crystoline\Xpsr\Webhooks\Inbound\VerifyWebhook;

$routes->post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])->middleware(VerifyWebhook::named('stripe'));
```

A request whose signature is missing or wrong gets 401; a verified one carries the name in its `webhook` request attribute and reaches the handler with its body intact. `Crystoline\Xpsr\Webhooks\Inbound\InboundSignatures` does the same in code: `check($name, $request)` answers true or false, `verify()` throws.

| Scheme | Verifies | Options |
|---|---|---|
| `stripe` | `Stripe-Signature: t=...,v1=...`, an HMAC-SHA256 of `timestamp.body` | `secret`, `tolerance` |
| `xpsr` | The same form in `X-Webhook-Signature`: webhooks from another XPSR application | `secret`, `header`, `tolerance` |
| `hmac` | A header holding an HMAC of the raw body | `secret`, `header`, `algorithm` (`sha256` default, `sha512`, ...), `encoding` (`hex` or `base64`), `prefix` (such as `sha256=`) |
| `token` | A header that must equal the secret | `secret`, `header` |

```php
'inbound' => [
    'stripe' => ['scheme' => 'stripe', 'secret' => env('STRIPE_WEBHOOK_SECRET')],
    'paystack' => ['scheme' => 'hmac', 'header' => 'x-paystack-signature', 'algorithm' => 'sha512', 'secret' => env('PAYSTACK_SECRET_KEY')],
    'github' => ['scheme' => 'hmac', 'header' => 'X-Hub-Signature-256', 'prefix' => 'sha256=', 'secret' => env('GITHUB_WEBHOOK_SECRET')],
    'shopify' => ['scheme' => 'hmac', 'header' => 'X-Shopify-Hmac-Sha256', 'encoding' => 'base64', 'secret' => env('SHOPIFY_WEBHOOK_SECRET')],
    'flutterwave' => ['scheme' => 'token', 'header' => 'verif-hash', 'secret' => env('FLUTTERWAVE_SECRET_HASH')],
    'partner' => ['scheme' => 'xpsr', 'secret' => env('PARTNER_WEBHOOK_SECRET')],
],
```

Timestamped schemes refuse a signature older than `tolerance` (5 minutes by default), which stops replays of a captured request. Comparisons are constant-time. Whether a webhook was already handled is the application's business: providers send the same event more than once, so key the work on the event's ID.

## Testing

Swap the service for the fake, which keeps what would have been sent:

```php
use Crystoline\Xpsr\Webhooks\Testing\FakeWebhooks;
use Crystoline\Xpsr\Webhooks\WebhooksInterface;

$this->swap(WebhooksInterface::class, $webhooks = new FakeWebhooks());
$this->postJson('/payments', [...])->assertCreated();
$webhooks->assertSent('payment.succeeded', fn(array $data): bool => $data['amount'] === 125000);
$webhooks->assertNotSent('refund.issued');
$webhooks->assertNothingSent();
```

To test a provider's webhook handler, sign the test request the way the provider does: `Signature::sign($secret, time(), $body)` for the `stripe` and `xpsr` schemes, `hash_hmac()` for `hmac`.

## Deployment

Deliveries run on the queue: run `php xpsr queue:work` as for any queued job, on a transport with retries (`database://`, `redis://`, ...). Schedule `webhooks:prune`. Endpoint secrets are encrypted with `APP_KEY`; rotating the key with `APP_PREVIOUS_KEYS` keeps them readable.
