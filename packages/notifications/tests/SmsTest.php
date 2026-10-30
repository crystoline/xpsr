<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests;

use Crystoline\Xpsr\HttpClient\HttpClientServiceRegistry;
use Crystoline\Xpsr\Notifications\NotificationFailedException;
use Crystoline\Xpsr\Notifications\NotifierInterface;
use Crystoline\Xpsr\Notifications\Sms\ArrayTransport;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\FailingSmsTransportFactory;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\SecurityAlert;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\User;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The sms channel's providers: log://, array://, a Symfony bridge, failover, and errors. */
final class SmsTest extends NotificationTestCase
{
    public function testSmsIsLoggedByDefault(): void
    {
        $application = $this->boot(['sms' => []]);
        $application->get(NotifierInterface::class)->send(new User(1, phone: '+2348012345678'), new SecurityAlert(['sms']));

        $logged = $this->logger->starting('SMS to {to}: {text}');
        self::assertCount(1, $logged);
        self::assertSame(['to' => '+2348012345678', 'from' => 'MPAY', 'text' => 'A new device signed in.'], $logged[0]['context']);
    }

    public function testAProviderBridgeSendsThroughTheApplicationsHttpClient(): void
    {
        $application = $this->boot(['sms' => ['dsn' => 'termii://TERMII-KEY@default?from=MPAY&channel=generic']], registries: [...self::REGISTRIES, HttpClientServiceRegistry::class]);
        $requests = [];
        $responses = [
            new MockResponse('{"message_id": "9122821270554876574", "message": "Successfully Sent"}', ['http_code' => 200]),
            new MockResponse('{"message": "Invalid API key"}', ['http_code' => 401]),
        ];
        $application->getContainer()->instance(HttpClientInterface::class, new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return array_shift($responses) ?? throw new \RuntimeException('No more responses.');
        }));
        $notifier = $application->get(NotifierInterface::class);

        $notifier->send(new User(1, phone: '+2348012345678'), new SecurityAlert(['sms']));
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.ng.termii.com/api/sms/send', $requests[0]['url']);
        self::assertEquals(
            ['api_key' => 'TERMII-KEY', 'sms' => 'A new device signed in.', 'from' => 'MPAY', 'to' => '+2348012345678', 'channel' => 'generic', 'type' => 'plain'],
            $requests[0]['body'],
        );

        // The second request gets 401.
        try {
            $notifier->send(new User(1, phone: '+2348012345678'), new SecurityAlert(['sms']));
            self::fail('The provider refused the key.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('sms for App\Models\User 1: Unable to send the SMS - status code: "401": "Invalid API key".', $exception->getMessage());
        }
    }

    public function testASecondProviderTakesOverWhenTheFirstFails(): void
    {
        $application = $this->boot(['sms' => ['dsn' => 'failing://default || array://default', 'factories' => [FailingSmsTransportFactory::class]]]);
        $application->get(NotifierInterface::class)->send(new User(1, phone: '+2348012345678'), new SecurityAlert(['sms']));

        self::assertCount(1, $application->get(ArrayTransport::class)->messages());
    }

    public function testAnUnknownProviderIsExplained(): void
    {
        $application = $this->boot(['sms' => ['dsn' => 'acme://KEY@default']]);
        try {
            $application->get(NotifierInterface::class)->send(new User(1, phone: '+2348012345678'), new SecurityAlert(['sms']));
            self::fail('There is no acme bridge.');
        } catch (NotificationFailedException $exception) {
            self::assertStringContainsString('notifications.sms.dsn: The "acme" scheme is not supported', $exception->getMessage());
            self::assertStringContainsString('XPSR adds log:// and array://.', $exception->getMessage());
        }

        $this->expectExceptionMessage('notifications.sms.dsn: The "acme" scheme is not supported');
        $application->get(TexterInterface::class);
    }

    public function testTheTexterSendsSmsOutsideNotifications(): void
    {
        $application = $this->boot();
        $application->get(TexterInterface::class)->send(new SmsMessage('+2348012345678', 'Your code is 123456.'));

        $sent = $application->get(ArrayTransport::class)->messages();
        self::assertSame('Your code is 123456.', $sent[0]->getSubject());
        $application->get(ArrayTransport::class)->clear();
        self::assertSame([], $application->get(ArrayTransport::class)->messages());
    }
}
