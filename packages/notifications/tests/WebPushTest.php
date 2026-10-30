<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry;
use Crystoline\Xpsr\Notifications\Console\WebPushKeysCommand;
use Crystoline\Xpsr\Notifications\Event\NotificationFailed;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationFailedException;
use Crystoline\Xpsr\Notifications\NotifierInterface;
use Crystoline\Xpsr\Notifications\PushSubscriptions;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Browser;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Payment;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\PaymentReceived;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\User;
use Crystoline\Xpsr\Notifications\WebPushMessage;
use Crystoline\Xpsr\Notifications\WebPushNotificationInterface;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The webpush channel: VAPID keys, subscriptions, and pushes through the application's HTTP
 * client, which a fake browser decrypts and checks as a real one and its push service would. On
 * the table make:push-subscriptions-table writes and on one laid out as Laravel's web push
 * channel's.
 */
final class WebPushTest extends NotificationTestCase
{
    private const CHROME = 'https://fcm.googleapis.com/fcm/send/dXJ0aGVyZTpBUEE5MWJF';
    private const FIREFOX = 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABnZ3Rlc3Q';

    /** @var array{public: string, private: string} */
    private array $keys;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /** @var array<string, int> endpoint => the push service's answer; 201 for others */
    private array $answers = [];

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        yield 'XPSR\'s migration' => ['xpsr'];
        yield 'Laravel\'s table' => ['laravel'];
    }

    public function testWebPushKeysMakesAPairAndCanWriteItToEnv(): void
    {
        $application = $this->boot();
        $tester = new CommandTester($application->get(WebPushKeysCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/\AVAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}\nVAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}\n\z/', $tester->getDisplay());

        file_put_contents($this->root . '/.env', "APP_NAME=Pay\nVAPID_PUBLIC_KEY=\n");
        self::assertSame(Command::SUCCESS, $tester->execute(['--write' => true]));
        $env = (string) file_get_contents($this->root . '/.env');
        self::assertMatchesRegularExpression('/\AAPP_NAME=Pay\nVAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}\nVAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}\n\z/', $env, 'An empty key is filled in; a missing one is added.');

        self::assertSame(Command::FAILURE, $tester->execute(['--write' => true]));
        self::assertStringContainsString('VAPID keys are already set in .env. Use --force to replace them', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame($env, file_get_contents($this->root . '/.env'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--write' => true, '--force' => true]));
        self::assertNotSame($env, file_get_contents($this->root . '/.env'));
        self::assertSame(2, preg_match_all('/^VAPID_/m', (string) file_get_contents($this->root . '/.env')));
    }

    #[DataProvider('tables')]
    public function testSubscribeKeepsWhatTheBrowserSentAndRefusesTheRest(string $table): void
    {
        $subscriptions = $this->application($table)->get(PushSubscriptions::class);
        $ada = new User(42);
        $browser = new Browser(self::CHROME);

        $kept = $subscriptions->subscribe($ada, $browser->subscription());
        self::assertSame([self::CHROME, $browser->p256dh, $browser->auth, 'aes128gcm'], [$kept->endpoint, $kept->publicKey, $kept->authToken, $kept->contentEncoding]);
        self::assertEquals([$kept], $subscriptions->of($ada));
        self::assertSame($this->keys['public'], $subscriptions->publicKey());

        $edge = new Browser('https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB');
        $subscriptions->subscribe($ada, $edge->subscription(), 'aesgcm');
        self::assertSame(['aes128gcm', 'aesgcm'], array_map(static fn($s): string => $s->contentEncoding, $subscriptions->of($ada)));

        $wrong = [
            'no keys' => [['endpoint' => self::CHROME], 'A push subscription has an endpoint and keys'],
            'http' => [['endpoint' => 'http://fcm.googleapis.com/fcm/send/x'] + $browser->subscription(), 'must be an https:// URL of a push service in notifications.webpush.hosts'],
            'another host' => [['endpoint' => 'https://169.254.169.254/latest/meta-data'] + $browser->subscription(), 'must be an https:// URL of a push service'],
            'a look-alike host' => [['endpoint' => 'https://notify.windows.com.example.net/w'] + $browser->subscription(), 'must be an https:// URL of a push service'],
            'credentials' => [['endpoint' => 'https://user:secret@fcm.googleapis.com/fcm/send/x'] + $browser->subscription(), 'must be an https:// URL of a push service'],
            'short key' => [['keys' => ['p256dh' => 'BAAA', 'auth' => $browser->auth]] + $browser->subscription(), 'p256dh key must be a P-256 public key'],
            'auth' => [['keys' => ['p256dh' => $browser->p256dh, 'auth' => 'c2VjcmV0']] + $browser->subscription(), 'auth key must be 16 bytes'],
        ];
        foreach ($wrong as $case => [$subscription, $message]) {
            try {
                $subscriptions->subscribe($ada, $subscription);
                self::fail("{$case}: refused");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $case);
            }
        }
        try {
            $subscriptions->subscribe($ada, $browser->subscription(), 'gzip');
            self::fail('gzip: refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('A push subscription\'s content encoding must be aes128gcm or aesgcm.', $exception->getMessage());
        }
        self::assertCount(2, $subscriptions->of($ada));
    }

    public function testHostsCanBeWidened(): void
    {
        $subscriptions = $this->application('xpsr', ['hosts' => ['*']])->get(PushSubscriptions::class);
        $subscriptions->subscribe(new User(42), (new Browser('https://push.example.org/subscription/1'))->subscription());
        self::assertCount(1, $subscriptions->of(new User(42)));
        self::assertFalse($subscriptions->allows('http://push.example.org/subscription/1'), 'https only, still.');
    }

    #[DataProvider('tables')]
    public function testABrowserBelongsToWhoeverSubscribedItLast(string $table): void
    {
        $subscriptions = $this->application($table)->get(PushSubscriptions::class);
        $ada = new User(42);
        $grace = new User(43);
        $browser = new Browser(self::CHROME);

        $subscriptions->subscribe($ada, $browser->subscription());
        $subscriptions->subscribe($ada, $browser->subscription());
        self::assertCount(1, $subscriptions->of($ada), 'Subscribing again keeps one row.');

        $subscriptions->subscribe($grace, $browser->subscription());
        self::assertSame([], $subscriptions->of($ada));
        self::assertCount(1, $subscriptions->of($grace));
        self::assertFalse($subscriptions->unsubscribe($ada, self::CHROME), 'Ada cannot unsubscribe Grace\'s browser.');
        self::assertTrue($subscriptions->unsubscribe($grace, self::CHROME));
        self::assertSame([], $subscriptions->of($grace));
    }

    #[DataProvider('tables')]
    public function testANotificationIsPushedEncryptedToEachBrowser(string $table): void
    {
        $application = $this->application($table);
        $chrome = new Browser(self::CHROME);
        $firefox = new Browser(self::FIREFOX);
        $subscriptions = $application->get(PushSubscriptions::class);
        $subscriptions->subscribe(new User(42), $chrome->subscription());
        $subscriptions->subscribe(new User(42), $firefox->subscription());

        $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));

        self::assertSame([self::CHROME, self::FIREFOX], array_column($this->requests, 'url'));
        foreach ([$chrome, $firefox] as $i => $browser) {
            $request = $this->requests[$i];
            self::assertSame('POST', $request['method']);
            self::assertSame(['aes128gcm', '86400', 'high', 'payments', 'application/octet-stream'], [
                $request['headers']['content-encoding'] ?? null,
                $request['headers']['ttl'] ?? null,
                $request['headers']['urgency'] ?? null,
                $request['headers']['topic'] ?? null,
                $request['headers']['content-type'] ?? null,
            ]);
            $claims = Browser::vapid($request['headers']['authorization'] ?? '', $this->keys['public']);
            self::assertSame('https://' . parse_url($browser->endpoint, PHP_URL_HOST), $claims['aud']);
            self::assertSame('mailto:push@pay.example.com', $claims['sub']);
            self::assertIsInt($claims['exp']);
            self::assertGreaterThan(time(), $claims['exp']);
            self::assertLessThanOrEqual(time() + 86400, $claims['exp'], 'Push services refuse a VAPID token that lasts more than a day.');

            $json = $browser->decrypt($request['body']);
            self::assertSame(
                '{"title":"Payment received","actions":[{"action":"view","title":"View"}],"body":"NGN 5000","icon":"/icons/payment.png","data":{"url":"/payments/PAY-1"}}',
                $json,
                'The title and options, in the order and without the empty ones, as Laravel\'s web push channel sends them.',
            );
        }
    }

    #[DataProvider('tables')]
    public function testAnExpiredBrowserIsForgottenAndTheOthersStillGetThePush(string $table): void
    {
        $application = $this->application($table);
        $subscriptions = $application->get(PushSubscriptions::class);
        $subscriptions->subscribe(new User(42), (new Browser(self::CHROME))->subscription());
        $subscriptions->subscribe(new User(42), (new Browser(self::FIREFOX))->subscription());
        $this->answers[self::CHROME] = 410;

        $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));

        self::assertSame([self::FIREFOX], array_map(static fn($s): string => $s->endpoint, $subscriptions->of(new User(42))));
        self::assertCount(1, $this->logger->starting('Deleted an expired push subscription'));
    }

    public function testABrowserThatFailsIsLoggedWhenAnotherGotThePush(): void
    {
        $application = $this->application('xpsr');
        $subscriptions = $application->get(PushSubscriptions::class);
        $subscriptions->subscribe(new User(42), (new Browser(self::CHROME))->subscription());
        $subscriptions->subscribe(new User(42), (new Browser(self::FIREFOX))->subscription());
        $this->answers[self::CHROME] = 503;

        $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));

        $warnings = $this->logger->starting('A browser of {recipient} did not get {notification}');
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertSame([User::TYPE . ' 42', 503], [$warnings[0]['context']['recipient'], $warnings[0]['context']['status']]);
        self::assertCount(2, $subscriptions->of(new User(42)), 'A browser that failed is kept.');
    }

    #[DataProvider('tables')]
    public function testAPushNoBrowserGotFailsTheChannelAndAQueuedOneIsRetried(string $table): void
    {
        $application = $this->application($table);
        $subscriptions = $application->get(PushSubscriptions::class);
        $subscriptions->subscribe(new User(42), (new Browser(self::CHROME))->subscription());
        $subscriptions->subscribe(new User(42), (new Browser(self::FIREFOX))->subscription());
        $this->answers = [self::CHROME => 500, self::FIREFOX => 429];

        try {
            $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));
            self::fail('No browser got it.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('webpush for App\Models\User 42: No browser of App\Models\User 42 got the web push (2 tried): ', $exception->getMessage());
            self::assertSame(['webpush'], array_column($exception->failures(), 'channel'), 'The other channels were sent.');
        }

        $this->requests = [];
        $this->recorder->events = [];
        $application->get(NotifierInterface::class)->queue(new User(42), new PaymentReceived(new Payment('PAY-2', 10)), queue: 'memory');
        $this->work($application, 'memory');
        self::assertCount(4, $this->requests, 'Two browsers, tried twice: the first try and one retry.');
        self::assertCount(2, array_filter($this->recorder->events, static fn(object $event): bool => $event instanceof NotificationFailed));
    }

    public function testRowsThePushServiceCannotTakeAreSkipped(): void
    {
        $application = $this->application('laravel');
        $application->get(PushSubscriptions::class)->subscribe(new User(42), (new Browser(self::CHROME))->subscription());
        $connection = $application->get(Connection::class);
        // Rows Laravel kept that XPSR would refuse: another host, a subscription without keys, and keys that are not keys.
        $other = new Browser('https://push.example.org/1');
        $rows = [
            [$other->endpoint, $other->p256dh, $other->auth],
            [self::FIREFOX, null, null],
            ['https://fcm.googleapis.com/fcm/send/broken', 'BAAA', 'c2VjcmV0'],
        ];
        foreach ($rows as [$endpoint, $key, $secret]) {
            $connection->insert("push_{$this->suffix}", ['subscribable_type' => User::TYPE, 'subscribable_id' => 42, 'endpoint' => $endpoint, 'public_key' => $key, 'auth_token' => $secret]);
        }
        self::assertCount(2, $application->get(PushSubscriptions::class)->of(new User(42)), 'Only rows with keys are subscriptions.');

        $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));

        self::assertSame([self::CHROME], array_column($this->requests, 'url'));
        $skipped = $this->logger->starting('Skipped a push subscription of {recipient}');
        self::assertCount(1, $skipped);
        self::assertSame('push.example.org', $skipped[0]['context']['host']);
    }

    public function testAMessageTooLargeForPushServicesFailsWhenItIsSent(): void
    {
        $application = $this->application('xpsr');
        $large = new class implements WebPushNotificationInterface {
            public function toWebPush(NotifiableInterface $to): WebPushMessage
            {
                return new WebPushMessage(title: 'Statement', body: str_repeat('x', 5000));
            }
        };

        try {
            $application->get(NotifierInterface::class)->send(new User(42), $large);
            self::fail('Too large.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('bytes as JSON; push services take at most 4078. Send a link in data instead of the content.', $exception->getMessage());
        }
        self::assertSame([], $this->requests);
    }

    public function testWithoutKeysTheChannelExplains(): void
    {
        $application = $this->boot(['webpush' => ['table' => "push_{$this->suffix}"]]);
        $this->migrate($application);
        $subscriptions = $application->get(PushSubscriptions::class);
        $subscriptions->subscribe(new User(42), (new Browser(self::CHROME))->subscription());

        try {
            $application->get(NotifierInterface::class)->send(new User(42), new PaymentReceived(new Payment('PAY-1', 5000)));
            self::fail('There are no keys.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('The webpush channel needs VAPID keys in notifications.webpush.public_key and private_key (VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY): php xpsr webpush:keys --write makes a pair.', $exception->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Set notifications.webpush.public_key (VAPID_PUBLIC_KEY): php xpsr webpush:keys --write makes a pair.');
        $subscriptions->publicKey();
    }

    public function testAMessageIsChecked(): void
    {
        $message = new WebPushMessage(title: 'Hi', body: '', renotify: false, vibrate: [200, 100], ttl: '2 hours', urgency: 'low');
        self::assertSame(['title' => 'Hi', 'vibrate' => [200, 100]], $message->payload(), 'Empty options are left out.');
        self::assertSame(['TTL' => 7200, 'urgency' => 'low'], $message->options());
        self::assertSame([], (new WebPushMessage('Hi'))->options());

        $wrong = [
            'title' => static fn() => new WebPushMessage(''),
            'action' => static fn() => new WebPushMessage('Hi', actions: [['title' => 'View']]),
            'dir' => static fn() => new WebPushMessage('Hi', dir: 'up'),
            'vibrate' => static fn() => new WebPushMessage('Hi', vibrate: ['long']),
            'ttl' => static fn() => new WebPushMessage('Hi', ttl: 'soon'),
            'negative ttl' => static fn() => new WebPushMessage('Hi', ttl: -1),
            'urgency' => static fn() => new WebPushMessage('Hi', urgency: 'urgent'),
            'topic' => static fn() => new WebPushMessage('Hi', topic: 'two words'),
        ];
        foreach ($wrong as $case => $make) {
            try {
                $make();
                self::fail("{$case}: refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $webpush */
    private function application(string $table, array $webpush = []): Application
    {
        $this->keys = WebPushKeysCommand::generate();
        $application = $this->boot(
            ['webpush' => $webpush + [
                'table' => "push_{$this->suffix}",
                'public_key' => $this->keys['public'],
                'private_key' => $this->keys['private'],
                'subject' => 'mailto:push@pay.example.com',
            ]],
            registries: [...self::REGISTRIES, HttpClientServiceRegistry::class],
        );
        $table === 'xpsr' ? $this->migrate($application) : $this->laravelTables($application);
        // The application's HTTP client, as crystoline/xpsr-http-client registers it, with a fake network.
        $application->getContainer()->instance(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', (string) $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = $options['body'] ?? '';
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => is_string($body) ? $body : throw new RuntimeException('A streamed body.')];

            return new MockResponse('', ['http_code' => $this->answers[$url] ?? 201]);
        }));

        return $application;
    }
}
