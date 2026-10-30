# crystoline/xpsr-notifications

Notifications for XPSR applications: one class says what to tell someone, and it goes out by email, by SMS, into an in-app inbox, and to their browsers. Examples: a payment received, a sign-in from a new device, or a one-time code.

```sh
composer require crystoline/xpsr-notifications
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Notifications\NotificationServiceRegistry::class,
```

Each channel needs its own pieces:

| Channel | Needs |
|---|---|
| `mail` | [`crystoline/xpsr-mail`](../mail/README.md) and its registry |
| `sms` | a provider's Symfony bridge, such as `symfony/termii-notifier` or `symfony/twilio-notifier` |
| `database` | [`crystoline/xpsr-database`](../database/README.md), and `php xpsr make:notifications-table` |
| `webpush` | `minishlink/web-push`, `crystoline/xpsr-database`, `php xpsr make:push-subscriptions-table`, and `php xpsr webpush:keys --write` |

`$notifier->queue()` also needs [`crystoline/xpsr-queue`](../queue/README.md). SMS providers and web push send through the application's HTTP client when [`crystoline/xpsr-http-client`](../http-client/README.md)'s registry is listed.

Create `config/notifications.php`:

```php
return [
    // The transport queue() uses; null for queue.default.
    'queue' => null,
    'sms' => [
        // termii://API_KEY@default?from=MPAY&channel=generic, twilio://SID:TOKEN@default?from=+15005550006, ...
        // "<dsn> || <dsn>" fails over to a second provider. log://default writes to the log.
        'dsn' => env('SMS_DSN', 'log://default'),
    ],
    'database' => ['table' => 'notifications', 'connection' => null],
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // A mailto: address or https:// URL where push services can reach you; default app.url.
        'subject' => env('VAPID_SUBJECT'),
        'table' => 'push_subscriptions',
    ],
    // Your own channels: name => class.
    'channels' => [],
];
```

## Writing a notification

`php xpsr make:notification PaymentReceived` creates `app/Notification/PaymentReceived.php`. Each channel interface a notification implements is a channel it goes to:

```php
use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Notifications\{DatabaseNotificationInterface, MailNotificationInterface, NotifiableInterface,
    SmsNotificationInterface, WebPushMessage, WebPushNotificationInterface};
use Symfony\Component\Mime\Email;

final class PaymentReceived implements MailNotificationInterface, SmsNotificationInterface, DatabaseNotificationInterface, WebPushNotificationInterface
{
    public function __construct(private readonly Payment $payment) {}

    public function toMail(NotifiableInterface $to, Mailer $mailer): Email
    {
        return $mailer->compose('emails.payment-received', ['payment' => $this->payment])->subject('Payment received');
    }

    public function toSms(NotifiableInterface $to): string
    {
        return "You received NGN {$this->payment->amount}. Ref {$this->payment->reference}";
    }

    public function toDatabase(NotifiableInterface $to): array
    {
        return ['reference' => $this->payment->reference, 'amount' => $this->payment->amount];
    }

    public function toWebPush(NotifiableInterface $to): WebPushMessage
    {
        return new WebPushMessage(title: 'Payment received', body: "NGN {$this->payment->amount}", data: ['url' => "/payments/{$this->payment->reference}"]);
    }
}
```

The messages are made when the notification is sent or queued, and only they are queued, so a notification can hold entities.

To choose channels for each recipient, such as by their preferences, add a `channels()` method. It returns some of the notification's channels:

```php
public function channels(NotifiableInterface $to): array
{
    return $to instanceof User && $to->sms_alerts ? ['mail', 'sms'] : ['mail'];
}
```

## Recipients

A recipient implements `NotifiableInterface`: its address on each channel, and its key in the notifications and push subscriptions tables.

```php
use Crystoline\Xpsr\Notifications\{NotifiableInterface, NotifiableKey};

final class User extends Model implements UserInterface, NotifiableInterface
{
    public function notificationAddress(string $channel): ?string
    {
        return match ($channel) {
            'mail' => $this->email,
            'sms' => $this->phone,     // in international format, such as +2348012345678
            default => null,
        };
    }

    public function notifiableKey(): NotifiableKey
    {
        return new NotifiableKey(self::class, $this->id);
    }
}
```

A recipient without an address on a channel, such as a user without a phone number, is skipped on that channel, and the skip is logged at the info level. Logs and errors name recipients by their key, never by their address.

For someone without an account, give the address:

```php
use Crystoline\Xpsr\Notifications\Recipient;

$notifier->send(Recipient::phone('+2348012345678'), new SignUpCode($code));
$notifier->send(Recipient::email('ada@example.com')->withPhone('+2348012345678'), $notification);
```

Such a recipient has no key, so the database and webpush channels skip it.

## Sending

```php
use Crystoline\Xpsr\Notifications\NotifierInterface;

$notifier->send($user, new PaymentReceived($payment));     // now
$notifier->queue($user, new PaymentReceived($payment));    // from php xpsr queue:work
$notifier->queue($user, $notification, delay: '10 minutes', queue: 'notifications');
$notifier->send($admins, new LargeTransfer($transfer));    // a list of recipients
```

- **`send()` tries every channel**, then throws `Crystoline\Xpsr\Notifications\NotificationFailedException` if any failed. If the email fails, the SMS still goes out. `failures()` lists each failed channel with its recipient and error.
- **`queue()` queues one job for each recipient and channel**, so a channel that fails is retried alone, and a retried SMS does not send the email again.
- **Each recipient gets one ID** for the notification, a UUID, the same on every channel. The database channel's row has it.

## Mail

The `mail` channel sends `toMail()`'s email through [the mailer](../mail/README.md). Without a To, it goes to the recipient's `mail` address; without a From, it gets `mail.from`.

## SMS

The `sms` channel sends through [Symfony Notifier](https://symfony.com/doc/current/notifier.html#sms-channel)'s providers, chosen by `notifications.sms.dsn`. Install the provider's bridge:

| Provider | Package | DSN |
|---|---|---|
| Termii | `symfony/termii-notifier` | `termii://API_KEY@default?from=SENDER&channel=generic` |
| Twilio | `symfony/twilio-notifier` | `twilio://SID:TOKEN@default?from=+15005550006` |
| Vonage | `symfony/vonage-notifier` | `vonage://KEY:SECRET@default?from=SENDER` |
| Infobip | `symfony/infobip-notifier` | `infobip://TOKEN@HOST?from=SENDER` |

Symfony has bridges for about fifty providers. `termii://… || twilio://…` sends through the second provider when the first fails. `log://default` writes each SMS to the application log, `array://default` keeps them in memory for tests, and `null://null` drops them.

`toSms()` returns the text, or a Symfony `SmsMessage` for a sender ID or a provider's options:

```php
return new SmsMessage((string) $to->notificationAddress('sms'), "Your code is {$this->code}", from: 'MPAY');
```

For a provider without a bridge, such as Africa's Talking, write a Symfony Notifier transport and its factory, and list the factory class in `notifications.sms.factories`. Or write a channel (see [Your own channels](#your-own-channels)).

`Symfony\Component\Notifier\TexterInterface` is a service too, for SMS outside notifications.

## The in-app inbox

The `database` channel keeps `toDatabase()`'s data in the notifications table. `php xpsr make:notifications-table`, then `php xpsr migrate`, creates the table. `Crystoline\Xpsr\Notifications\Inbox` reads it:

```php
$inbox->list($user);                          // newest first, 20 at a time
$inbox->list($user, unreadOnly: true, limit: 50, offset: 50);
$inbox->unreadCount($user);
$inbox->markAsRead($user, $id);               // also several IDs
$inbox->markAllAsRead($user);
$inbox->markAsUnread($user, $id);
$inbox->delete($user, $id);
```

Every call is limited to its user's notifications, so IDs from a request are safe to pass: another user's ID changes nothing.

`list()` returns `StoredNotification`s, whose JSON is Laravel's: `{"id", "type", "data", "read_at", "created_at"}`, with dates in UTC as `2026-10-30T12:00:00.000000Z`. A controller can return them as they are.

The row's `type` is the notification's class, or what its `databaseType()` method returns, if it has one.

## Web push

The `webpush` channel pushes `toWebPush()`'s message to each browser the recipient subscribed.

1. **Make the keys:** `php xpsr webpush:keys --write` sets `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` in `.env`. Keep them: browsers subscribe with the public key, and new keys stop pushes until they subscribe again.
2. **Create the table:** `php xpsr make:push-subscriptions-table`, then `php xpsr migrate`.
3. **Keep each browser's subscription**, with routes like these, using `Crystoline\Xpsr\Notifications\PushSubscriptions`:

```php
// GET /push/key
return ['key' => $subscriptions->publicKey()];

// POST /push/subscriptions, with the browser's subscription
$subscriptions->subscribe($user, $request->getParsedBody());

// DELETE /push/subscriptions
$subscriptions->unsubscribe($user, $endpoint);
```

In the page:

```js
const registration = await navigator.serviceWorker.register('/sw.js');
const { key } = await (await fetch('/push/key')).json();
const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
await fetch('/push/subscriptions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(subscription) });
```

And in `sw.js`:

```js
self.addEventListener('push', (event) => {
    const message = event.data.json();
    event.waitUntil(self.registration.showNotification(message.title, message));
});
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    if (event.notification.data?.url) event.waitUntil(clients.openWindow(event.notification.data.url));
});
```

The browser gets JSON: the title and the [Notification options](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerRegistration/showNotification) (`body`, `icon`, `image`, `badge`, `tag`, `data`, `actions`, and so on). A message's `ttl`, `urgency`, and `topic` go to the push service instead. A message can be at most 4,078 bytes as JSON; put a link in `data` for more.

- **The server sends requests to the endpoints browsers give it.** `subscribe()` therefore accepts only https endpoints of the push services in `notifications.webpush.hosts`: by default those of Chrome and other Chromium browsers, Firefox, Edge, and Safari. `['*']` accepts any host.
- **A subscription the push service says has expired is deleted.**
- **Other failures are logged.** The delivery fails, and a queued one is retried, only when no browser got the push: a retry sends it to every browser again.
- **A browser subscribed for one user moves** to the next user who subscribes it, such as after a different user signs in on it.
- **The GMP or BCMath PHP extension** makes the encryption faster; `minishlink/web-push` logs a notice without either.

## Your own channels

A channel implements `Crystoline\Xpsr\Notifications\ChannelInterface`, and is listed in `notifications.channels`:

```php
'channels' => ['slack' => App\Notification\SlackChannel::class],
```

```php
interface SlackNotificationInterface extends NotificationInterface
{
    public function toSlack(NotifiableInterface $to): string;
}

final class SlackChannel implements ChannelInterface
{
    public function __construct(private readonly ClientInterface $http, private readonly RequestFactoryInterface $requests) {}

    public function supports(NotificationInterface $notification): bool
    {
        return $notification instanceof SlackNotificationInterface;
    }

    // Made when the notification is sent or queued; null skips the recipient.
    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): mixed
    {
        $webhook = $to->notificationAddress($channel);

        return $webhook === null ? null : ['webhook' => $webhook, 'text' => $notification->toSlack($to)];
    }

    // Now, or in a worker. Throw when it fails.
    public function deliver(Delivery $delivery): void
    {
        // POST ['text' => $delivery->message['text']] as JSON to $delivery->message['webhook'], and throw unless Slack answers 200.
    }
}
```

The container makes the channel, so its constructor can take services. A channel named `mail`, `sms`, `database`, or `webpush` replaces the built-in one.

## Events

With the application's events, each delivery dispatches `Crystoline\Xpsr\Notifications\Event\NotificationSent` or `NotificationFailed`, for example for an audit log. Both carry the `Delivery`: the notification's ID, class, channel, message, and the recipient's key. `NotificationFailed` is dispatched on each failed attempt, including those a worker retries. A listener that throws is logged, so a sent notification is not reported as failed and sent again.

## Coming from Laravel

| Laravel | XPSR |
|---|---|
| `via($notifiable)` | the channel interfaces a notification implements, or its `channels()` |
| `toMail()` returning a `MailMessage` | `toMail()` returning a Symfony `Email`, usually from a view |
| `toArray()` / `toDatabase()` | `toDatabase()` |
| `toVonage()`, SMS channel packages | `toSms()`, with the provider's DSN |
| `toWebPush()` (`laravel-notification-channels/webpush`) | `toWebPush()` returning a `WebPushMessage` |
| `routeNotificationForMail()` and others | `notificationAddress($channel)` |
| `Notification::route('mail', ...)` | `Recipient::email(...)` |
| `$user->notify()`, `ShouldQueue` | `$notifier->send()`, `$notifier->queue()` |
| `$user->notifications`, `unreadNotifications`, `markAsRead()` | `Inbox` |
| `$user->updatePushSubscription()` | `PushSubscriptions::subscribe()` |

**The tables are Laravel's.** The notifications table and `laravel-notification-channels/webpush`'s `push_subscriptions` table are read and written as Laravel does. With the same `VAPID_*` keys, an application that moves keeps its users' notifications and their subscribed browsers, and its service worker reads the same JSON. Give `NotifiableKey` the type Laravel stored, the model's class or its morph map alias, and set `database.table` and `webpush.table` if yours differ. The rows' `type` stays the Laravel class name, which `Inbox` returns as it is.

## Testing

```php
use Crystoline\Xpsr\Notifications\NotifierInterface;
use Crystoline\Xpsr\Notifications\Testing\FakeNotifier;

$this->swap(NotifierInterface::class, $notifier = $this->application()->get(FakeNotifier::class));
$this->postJson('/payments', [...]);

$sent = $notifier->sentTo($user, PaymentReceived::class);
self::assertCount(1, $sent);
self::assertSame(['mail', 'sms', 'database'], $sent[0]->channels());
self::assertStringContainsString('NGN 5000', $sent[0]->message('sms')->getSubject());
```

The fake makes each channel's message, as the notifier does, and keeps it instead of sending it, so a template error still fails the test. A recipient with a key matches another instance of the same user.

To send for real in tests, set `SMS_DSN=array://default` and `MAIL_DSN=array://default`, and read `Crystoline\Xpsr\Notifications\Sms\ArrayTransport::messages()` and the mail package's `ArrayTransport::emails()`.

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Notifications\NotifierInterface` | `send()` and `queue()`. Shared. |
| `Crystoline\Xpsr\Notifications\Inbox` | The database channel's notifications. Shared. |
| `Crystoline\Xpsr\Notifications\PushSubscriptions` | The browsers subscribed to web push. Shared. |
| `Symfony\Component\Notifier\TexterInterface` | The SMS provider, for SMS outside notifications. Shared. |
| `Crystoline\Xpsr\Notifications\Sms\ArrayTransport` | The SMS sent through `array://`. |

Commands: `make:notification`, `make:notifications-table`, `make:push-subscriptions-table`, and `webpush:keys` (`--write` sets the keys in `.env`; `--force` replaces keys that are set).

This package's tests run against SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17. Web push is checked by decrypting each push as a browser would and verifying its VAPID signature.

Requires PHP 8.4+.
