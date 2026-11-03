<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests;

use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Broadcast\BroadcastOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BroadcastOptionsTest extends TestCase
{
    private const SECRET = '!ChangeThisMercureHubJWTSecretKey!';

    public function testDefaultsAndValues(): void
    {
        $defaults = BroadcastOptions::from([]);
        self::assertNull($defaults->hub);
        self::assertNull($defaults->publicHub);
        self::assertSame('HS256', $defaults->algorithm);
        self::assertSame('mercure', $defaults->driver);
        self::assertTrue($defaults->queue);
        self::assertNull($defaults->client);
        self::assertSame([], $defaults->subscriptions);
        self::assertSame(3600, $defaults->ttl);
        self::assertSame('mercureAuthorization', $defaults->cookie);
        self::assertNull($defaults->cookieDomain);

        $environment = BroadcastOptions::from([], ['BROADCAST_HUB_URL' => 'http://localhost:3000/.well-known/mercure', 'BROADCAST_SECRET' => 'base64:' . base64_encode(self::SECRET), 'BROADCAST_DRIVER' => 'null', 'BROADCAST_PUBLIC_HUB_URL' => '']);
        self::assertSame('http://localhost:3000/.well-known/mercure', $environment->hub);
        self::assertSame('http://localhost:3000/.well-known/mercure', $environment->publicHub, 'The public URL defaults to the hub.');
        self::assertSame(self::SECRET, $environment->publisherSecret(), 'base64: is decoded.');
        self::assertSame(self::SECRET, $environment->subscriberSecret());
        self::assertSame('null', $environment->driver);

        $selectors = static fn(UserInterface $user): array => ['*'];
        $options = BroadcastOptions::from([
            'hub' => 'http://hub.internal/.well-known/mercure',
            'public_hub' => 'https://hub.example.com/.well-known/mercure',
            'secret' => self::SECRET,
            'publisher_secret' => str_repeat('p', 32),
            'algorithm' => 'hs256',
            'driver' => 'Mercure',
            'queue' => 'broadcasts',
            'client' => 'hub',
            'subscriptions' => $selectors,
            'ttl' => '15 minutes',
            'cookie' => 'mercure_auth',
            'cookie_domain' => 'example.com',
        ], ['BROADCAST_HUB_URL' => 'http://ignored.test/']);
        self::assertSame('http://hub.internal/.well-known/mercure', $options->hub, 'The configuration wins over the environment.');
        self::assertSame('https://hub.example.com/.well-known/mercure', $options->publicHub);
        self::assertSame(str_repeat('p', 32), $options->publisherSecret());
        self::assertSame(self::SECRET, $options->subscriberSecret(), 'The subscriber key defaults to the secret.');
        self::assertSame('HS256', $options->algorithm);
        self::assertSame('mercure', $options->driver);
        self::assertSame('broadcasts', $options->queue);
        self::assertSame('hub', $options->client);
        self::assertSame($selectors, $options->subscriptions);
        self::assertSame(900, $options->ttl);
        self::assertSame('mercure_auth', $options->cookie);
        self::assertSame('example.com', $options->cookieDomain);

        self::assertNull(BroadcastOptions::from(['ttl' => null])->ttl, 'A null ttl: for the browser session.');
        self::assertSame(['announcements'], BroadcastOptions::from(['subscriptions' => ['announcements']])->subscriptions);
        self::assertFalse(BroadcastOptions::from(['queue' => false])->queue);
        self::assertSame(str_repeat('k', 48), BroadcastOptions::from(['secret' => str_repeat('k', 48), 'algorithm' => 'HS384'])->publisherSecret());
    }

    public function testTheHubAndTheSecretAreNeededWhenUsed(): void
    {
        $options = BroadcastOptions::from([]);
        try {
            $options->hubUrl();
            self::fail('No hub.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('broadcast.hub is not set (BROADCAST_HUB_URL)', $e->getMessage());
        }
        try {
            $options->publisherSecret();
            self::fail('No secret.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('broadcast.secret is not set (BROADCAST_SECRET)', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown option' => [['url' => 'x'], 'Unknown option broadcast.url. Known options: hub, public_hub'];
        yield 'hub' => [['hub' => 'localhost:3000'], 'broadcast.hub (or BROADCAST_HUB_URL) must be an http or https URL'];
        yield 'hub scheme' => [['hub' => 'ftp://hub.test/'], 'broadcast.hub (or BROADCAST_HUB_URL) must be an http or https URL'];
        yield 'public hub' => [['public_hub' => 'nope'], 'broadcast.public_hub (or BROADCAST_PUBLIC_HUB_URL) must be an http or https URL'];
        yield 'secret short' => [['secret' => 'short'], 'broadcast.secret (or BROADCAST_SECRET) must be at least 32 bytes for HS256; give the hub the same key.'];
        yield 'secret for HS512' => [['secret' => self::SECRET, 'algorithm' => 'HS512'], 'must be at least 64 bytes for HS512'];
        yield 'secret base64' => [['secret' => 'base64:***'], 'broadcast.secret (or BROADCAST_SECRET) starts with "base64:" but the rest is not base64.'];
        yield 'secret type' => [['secret' => 42], 'broadcast.secret (or BROADCAST_SECRET) must be a string'];
        yield 'publisher secret' => [['publisher_secret' => 'short'], 'broadcast.publisher_secret must be at least 32 bytes'];
        yield 'algorithm' => [['algorithm' => 'RS256'], 'broadcast.algorithm must be HS256, HS384, or HS512.'];
        yield 'driver' => [['driver' => 'pusher'], 'broadcast.driver (or BROADCAST_DRIVER) must be mercure or null.'];
        yield 'queue' => [['queue' => ''], 'broadcast.queue must be true, false, or the name of a queue transport.'];
        yield 'client' => [['client' => ''], 'broadcast.client must be the name of an http_client.clients entry.'];
        yield 'subscriptions' => [['subscriptions' => 'users/*'], 'broadcast.subscriptions must be a list of topic selectors, or a closure'];
        yield 'subscriptions empty selector' => [['subscriptions' => ['']], 'broadcast.subscriptions must be a list of topic selectors'];
        yield 'ttl' => [['ttl' => 'soon'], 'broadcast.ttl must be a number of seconds above zero, or a period such as "1 hour".'];
        yield 'cookie' => [['cookie' => 'my cookie'], 'broadcast.cookie must be a cookie name, such as mercureAuthorization.'];
        yield 'cookie domain' => [['cookie_domain' => ''], 'broadcast.cookie_domain must be a domain, such as example.com, or null.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidOptionsNameTheSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        BroadcastOptions::from($config);
    }
}
