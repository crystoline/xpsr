<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast\Tests;

use Crystoline\Xpsr\Broadcast\BroadcastOptions;
use Crystoline\Xpsr\Broadcast\Hub;
use Crystoline\Xpsr\Broadcast\HubException;
use Crystoline\Xpsr\Broadcast\Tokens;
use Crystoline\Xpsr\Broadcast\Update;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use DateTimeInterface;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

final class HubTest extends TestCase
{
    private const HUB = 'http://hub.internal:3000/.well-known/mercure';
    private const SECRET = '!ChangeThisMercureHubJWTSecretKey!';

    public function testAnUpdateHasTopicsAndDataAndRefusesBadValues(): void
    {
        $update = new Update('orders/42', ['status' => 'paid', 'amount' => 10.0, 'note' => 'café/é']);
        self::assertSame(['orders/42'], $update->topics);
        self::assertSame('{"status":"paid","amount":10.0,"note":"café/é"}', $update->data, 'JSON with slashes and Unicode kept, and 10.0 as a float.');
        self::assertSame(['status' => 'paid', 'amount' => 10.0, 'note' => 'café/é'], $update->json());
        self::assertFalse($update->private);
        self::assertNull($update->type);
        self::assertTrue($update->hasTopic('orders/42'));
        self::assertFalse($update->hasTopic('orders/4'));

        $text = new Update(['a', 'b'], 'plain text', private: true, type: 'ping', id: 'u1', retry: 5000);
        self::assertSame(['a', 'b'], $text->topics);
        self::assertSame('plain text', $text->data, 'A string is sent as it is.');
        self::assertSame('topic=a&topic=b&data=plain%20text&private=on&id=u1&type=ping&retry=5000', Hub::form($text));
        self::assertSame('topic=orders%2F42&data=%7B%22status%22%3A%22paid%22%2C%22amount%22%3A10.0%2C%22note%22%3A%22caf%C3%A9%2F%C3%A9%22%7D', Hub::form($update));

        foreach ([
            [[], 'x', 'An update needs at least one topic.'],
            [[''], 'x', 'A topic must be a non-empty string'],
            [["bad\ntopic"], 'x', 'A topic must be a non-empty string'],
            [[42], 'x', 'A topic must be a non-empty string'],
            ['a', ['note' => "\xB1\x31"], 'The update data cannot be encoded as JSON'],
        ] as [$topics, $data, $message]) {
            try {
                new Update($topics, $data);
                self::fail($message);
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        try {
            new Update('a', 'x', type: "a\nb");
            self::fail('A type with a newline.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("An update's type must be a non-empty string without control characters.", $e->getMessage());
        }
        try {
            new Update('a', 'x', retry: -1);
            self::fail('A negative retry.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("An update's retry must be 0 or more milliseconds.", $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The update data is not JSON');
        (new Update('a', 'not json'))->json();
    }

    public function testPublishingPostsTheFormToTheHubWithThePublisherToken(): void
    {
        $factory = new Psr17Factory();
        $client = new FakeClient($factory->createResponse(200)->withBody($factory->createStream("urn:uuid:3f5c\n")));
        $hub = $this->hub($client);

        $id = $hub->publish(new Update(['orders/42', 'users/7/orders'], ['status' => 'paid'], private: true, type: 'order.paid'));

        self::assertSame('urn:uuid:3f5c', $id);
        $request = $client->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::HUB, (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('topic=orders%2F42&topic=users%2F7%2Forders&data=%7B%22status%22%3A%22paid%22%7D&private=on&type=order.paid', (string) $request->getBody());
        self::assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));
        $token = $this->parse(substr($request->getHeaderLine('Authorization'), 7), self::SECRET);
        self::assertSame(['publish' => ['*']], $token->claims()->get('mercure'), 'The publisher token allows every topic.');
        self::assertFalse($token->claims()->has('exp'), 'The publisher token does not expire.');
        self::assertSame(1793700000, self::at($token, 'iat'));

        $client->queue($factory->createResponse(200));
        self::assertNull($hub->publish(new Update('a', 'x')), 'A hub that answers no ID.');
    }

    public function testTheHubsRefusalAndAbsenceAreReportedWithTheUrl(): void
    {
        $factory = new Psr17Factory();
        $client = new FakeClient(
            $factory->createResponse(401)->withBody($factory->createStream('Unauthorized')),
            static fn(RequestInterface $request): never => throw new class ('Connection refused', $request) extends RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            },
        );
        $hub = $this->hub($client);
        try {
            $hub->publish(new Update('orders/42', 'x'));
            self::fail('Refused.');
        } catch (HubException $e) {
            self::assertSame('The Mercure hub at http://hub.internal:3000/.well-known/mercure answered 401 to the update for orders/42: Unauthorized', $e->getMessage());
        }
        try {
            $hub->publish(new Update('orders/42', 'x'));
            self::fail('Unreachable.');
        } catch (HubException $e) {
            self::assertSame('The Mercure hub at http://hub.internal:3000/.well-known/mercure could not be reached: Connection refused', $e->getMessage());
            self::assertInstanceOf(NetworkExceptionInterface::class, $e->getPrevious());
        }
    }

    public function testTheSubscribeUrlCarriesTheTopics(): void
    {
        $hub = $this->hub(new FakeClient(), ['public_hub' => 'https://hub.example.com/.well-known/mercure']);
        self::assertSame('https://hub.example.com/.well-known/mercure', $hub->url());
        self::assertSame('https://hub.example.com/.well-known/mercure?topic=orders%2F42', $hub->url('orders/42'));
        self::assertSame('https://hub.example.com/.well-known/mercure?topic=orders%2F42&topic=news&lastEventID=urn%3Auuid%3A1', $hub->url(['orders/42', 'news'], 'urn:uuid:1'));
        self::assertSame(self::HUB . '?topic=a', $this->hub(new FakeClient())->url('a'), 'The hub URL when no public one is set.');
        self::assertSame('https://hub.example.com/hub?x=1&topic=a', $this->hub(new FakeClient(), ['public_hub' => 'https://hub.example.com/hub?x=1'])->url('a'));
    }

    public function testSubscriberTokensListTheSelectorsAndExpire(): void
    {
        $clock = new MockClock('2026-11-03 10:00:00', 'UTC');
        $tokens = new Tokens(BroadcastOptions::from(['secret' => self::SECRET, 'ttl' => '15 minutes']), $clock);
        $token = $this->parse($tokens->subscriber(['users/7/{+rest}', 'announcements']), self::SECRET);
        self::assertSame(['subscribe' => ['users/7/{+rest}', 'announcements']], $token->claims()->get('mercure'));
        self::assertSame(1793700000 + 900, self::at($token, 'exp'));
        self::assertSame(1793700000, self::at($token, 'iat'));

        $never = new Tokens(BroadcastOptions::from(['secret' => self::SECRET, 'ttl' => null]), $clock);
        self::assertFalse($this->parse($never->subscriber(['*']), self::SECRET)->claims()->has('exp'));

        $keys = new Tokens(BroadcastOptions::from(['publisher_secret' => str_repeat('p', 64), 'subscriber_secret' => str_repeat('s', 64), 'algorithm' => 'HS512']), $clock);
        $subscriber = (new Parser(new JoseEncoder()))->parse($keys->subscriber(['*']));
        self::assertInstanceOf(UnencryptedToken::class, $subscriber);
        self::assertSame('HS512', $subscriber->headers()->get('alg'));
        self::assertTrue((new Validator())->validate($subscriber, new SignedWith(new Sha512(), InMemory::plainText(str_repeat('s', 64)))), 'Signed with the subscriber key.');
        self::assertFalse((new Validator())->validate($subscriber, new SignedWith(new Sha512(), InMemory::plainText(str_repeat('p', 64)))));
        $publisher = (new Parser(new JoseEncoder()))->parse($keys->publisher());
        self::assertTrue((new Validator())->validate($publisher, new SignedWith(new Sha512(), InMemory::plainText(str_repeat('p', 64)))), 'Signed with the publisher key.');
        self::assertSame($keys->publisher(), $keys->publisher(), 'The publisher token is made once.');
    }

    /** @param array<string, mixed> $options */
    private function hub(FakeClient $client, array $options = []): Hub
    {
        $factory = new Psr17Factory();
        $options = BroadcastOptions::from($options + ['hub' => self::HUB, 'secret' => self::SECRET]);

        return new Hub($options, new Tokens($options, new MockClock('2026-11-03 10:00:00', 'UTC')), $client, $factory, $factory);
    }

    /** A date claim as a timestamp. */
    private static function at(UnencryptedToken $token, string $claim): ?int
    {
        $value = $token->claims()->get($claim);

        return $value instanceof DateTimeInterface ? $value->getTimestamp() : null;
    }

    private function parse(string $jwt, string $secret): UnencryptedToken
    {
        $token = (new Parser(new JoseEncoder()))->parse($jwt);
        self::assertInstanceOf(UnencryptedToken::class, $token);
        self::assertTrue((new Validator())->validate($token, new SignedWith(new Sha256(), InMemory::plainText($secret))), 'Signed with the secret.');

        return $token;
    }
}
