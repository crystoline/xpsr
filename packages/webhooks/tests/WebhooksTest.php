<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests;

use Crystoline\Xpsr\Encryption\Encrypter;
use Crystoline\Xpsr\Webhooks\Console\PruneCommand;
use Crystoline\Xpsr\Webhooks\Console\ResendCommand;
use Crystoline\Xpsr\Webhooks\Delivery;
use Crystoline\Xpsr\Webhooks\DeliveryFailedException;
use Crystoline\Xpsr\Webhooks\Event\WebhookDelivered;
use Crystoline\Xpsr\Webhooks\Event\WebhookFailed;
use Crystoline\Xpsr\Webhooks\Signature;
use Crystoline\Xpsr\Webhooks\Tests\Fixtures\Project;
use Crystoline\Xpsr\Webhooks\WebhooksInterface;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Endpoints, sending, retries, and the commands, on SQLite with the queue's sync:// transport. */
final class WebhooksTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testEndpointsAreStoredWithTheirSecretsEncrypted(): void
    {
        $endpoints = $this->project->endpoints();

        $endpoint = $endpoints->add('https://203.0.113.10/hooks', ['payment.*', 'refund.issued'], 'Shop');
        self::assertStringStartsWith('whsec_', $endpoint->secret);
        self::assertTrue($endpoint->enabled);
        self::assertSame(['payment.*', 'refund.issued'], $endpoint->events);
        $row = $this->project->db()->fetchAssociative('SELECT * FROM webhook_endpoints WHERE id = ?', [$endpoint->id]);
        self::assertIsArray($row);
        self::assertNotSame($endpoint->secret, $row['secret'], 'The secret is not stored in clear.');
        self::assertSame($endpoint->secret, $this->project->application->get(Encrypter::class)->decryptString((string) $row['secret']));
        self::assertSame('Shop', $endpoints->find($endpoint->id)?->label);
        self::assertSame($endpoint->secret, $endpoints->find($endpoint->id)->secret);

        $all = $endpoints->add('https://203.0.113.11/all');
        self::assertSame(['*'], $all->events);
        self::assertSame([$endpoint->id, $all->id], array_column($endpoints->for('payment.succeeded'), 'id'), 'payment.* and * match.');
        self::assertSame([$endpoint->id, $all->id], array_column($endpoints->for('refund.issued'), 'id'));
        self::assertSame([$all->id], array_column($endpoints->for('refund.failed'), 'id'), 'An exact name does not match its family.');

        $endpoints->disable($endpoint->id);
        self::assertSame([$all->id], array_column($endpoints->for('payment.succeeded'), 'id'), 'A disabled endpoint gets nothing.');
        self::assertNotNull($endpoints->find($endpoint->id)?->disabledAt);
        $endpoints->enable($endpoint->id);
        self::assertCount(2, $endpoints->for('payment.succeeded'));

        $rotated = $endpoints->rotateSecret($endpoint->id);
        self::assertNotSame($endpoint->secret, $rotated->secret);
        self::assertSame($rotated->secret, $endpoints->find($endpoint->id)?->secret);

        $endpoints->remove($all->id);
        self::assertNull($endpoints->find($all->id));
        self::assertNull($endpoints->find('not-an-id'));
        self::assertCount(1, $endpoints->all());
    }

    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'ftp' => ['ftp://203.0.113.10/hooks'];
        yield 'no host' => ['https:///hooks'];
        yield 'credentials' => ['https://user:pass@203.0.113.10/hooks'];
        yield 'loopback' => ['http://127.0.0.1/hooks'];
        yield 'private' => ['http://10.1.2.3/hooks'];
        yield 'link-local (cloud metadata)' => ['http://169.254.169.254/latest/meta-data'];
        yield 'ipv6 loopback' => ['http://[::1]/hooks'];
        yield 'mapped ipv4' => ['http://[::ffff:10.0.0.1]/hooks'];
        yield 'localhost' => ['http://localhost/hooks'];
        yield 'text' => ['not a url'];
    }

    #[DataProvider('refusedUrls')]
    public function testEndpointUrlsThatWebhooksMayNotGoToAreRefused(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->project->endpoints()->add($url);
    }

    public function testPrivateUrlsCanBeAllowedForDevelopment(): void
    {
        $project = new Project(['allow_private_urls' => true]);
        $project->boot();
        try {
            self::assertSame('http://127.0.0.1:8080/hooks', $project->endpoints()->add('http://127.0.0.1:8080/hooks')->url);
        } finally {
            $project->remove();
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An endpoint needs one or more event names');
        $this->project->endpoints()->add('https://203.0.113.10/hooks', []);
    }

    public function testSendDeliversSignedJsonToTheSubscribedEndpoints(): void
    {
        $endpoints = $this->project->endpoints();
        $payments = $endpoints->add('https://203.0.113.10/payments', ['payment.*']);
        $endpoints->add('https://203.0.113.11/refunds', ['refund.*']);
        $this->project->client->queue(new Response(200, [], '{"ok":true}'));
        $webhooks = $this->project->application->get(WebhooksInterface::class);

        $deliveries = $webhooks->send('payment.succeeded', ['id' => 7, 'amount' => 125000, 'currency' => 'NGN']);

        self::assertCount(1, $deliveries, 'Only the endpoint subscribed to payment.* gets it.');
        $delivery = $this->project->deliveries()->find($deliveries[0]->id);
        self::assertInstanceOf(Delivery::class, $delivery);
        self::assertSame(Delivery::DELIVERED, $delivery->status, 'On sync://, the delivery ran at once.');
        self::assertSame(1, $delivery->attempts);
        self::assertSame(200, $delivery->lastStatus);
        self::assertNotNull($delivery->deliveredAt);
        self::assertSame($payments->id, $delivery->endpointId);
        self::assertSame(['id' => 7, 'amount' => 125000, 'currency' => 'NGN'], $delivery->payload);

        $this->project->client->assertRequested('POST https://203.0.113.10/payments', 1);
        $request = $this->project->client->lastRequest();
        $body = (string) $request->getBody();
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('XPSR-Webhooks', $request->getHeaderLine('User-Agent'));
        self::assertSame($delivery->id, $request->getHeaderLine('X-Webhook-Id'));
        self::assertSame('payment.succeeded', $request->getHeaderLine('X-Webhook-Event'));
        self::assertSame((string) $this->project->clock->now()->getTimestamp(), $request->getHeaderLine('X-Webhook-Timestamp'));
        self::assertTrue(Signature::verify($payments->secret, $request->getHeaderLine('X-Webhook-Signature'), $body, $this->project->clock->now()->getTimestamp(), 300), 'The receiver can verify with the endpoint secret.');
        self::assertFalse(Signature::verify('other-secret', $request->getHeaderLine('X-Webhook-Signature'), $body, $this->project->clock->now()->getTimestamp(), 300));
        self::assertSame(
            ['id' => $delivery->id, 'event' => 'payment.succeeded', 'created_at' => '2026-11-02T10:00:00+00:00', 'data' => ['id' => 7, 'amount' => 125000, 'currency' => 'NGN']],
            json_decode($body, true),
        );
        $events = $this->project->events->of(WebhookDelivered::class);
        self::assertCount(1, $events);
        self::assertSame($delivery->id, $events[0]->delivery->id);
        self::assertSame($payments->id, $events[0]->endpoint->id);

        self::assertSame([], $webhooks->send('invoice.created', []), 'No subscriber, nothing sent.');
        self::assertSame([$delivery->id], array_column($this->project->deliveries()->forEndpoint($payments->id), 'id'));
    }

    public function testADeliveryWaitsForTheTransactionToCommit(): void
    {
        $this->project->endpoints()->add('https://203.0.113.10/hooks');
        $this->project->client->queue(new Response(204));
        $db = $this->project->db();

        $db->beginTransaction();
        $deliveries = $this->project->webhooks()->send('payment.succeeded', ['id' => 1]);
        self::assertSame(Delivery::PENDING, $this->project->deliveries()->find($deliveries[0]->id)?->status);
        $this->project->client->assertNothingRequested();
        $db->commit();

        $this->project->client->assertRequested('POST https://203.0.113.10/hooks');
        self::assertSame(Delivery::DELIVERED, $this->project->deliveries()->find($deliveries[0]->id)->status);
    }

    public function testAFailingEndpointIsRecordedReportedAndCanBeResent(): void
    {
        $endpoint = $this->project->endpoints()->add('https://203.0.113.10/hooks');
        $this->project->client->queue(new Response(500, [], "<html>\n  Server Error\n</html>"));
        $webhooks = $this->project->webhooks();

        try {
            $webhooks->send('payment.succeeded', ['id' => 1]);
            self::fail('On sync://, there are no retries, so the failure reaches the caller.');
        } catch (DeliveryFailedException $exception) {
            self::assertStringContainsString('failed on attempt 1: HTTP 500: <html> Server Error </html>', $exception->getMessage());
        }
        $delivery = $this->project->deliveries()->find($exception->delivery->id);
        self::assertInstanceOf(Delivery::class, $delivery);
        self::assertSame(Delivery::FAILED, $delivery->status, 'The queue gave up, so the job\'s failed() recorded it.');
        self::assertSame(1, $delivery->attempts);
        self::assertSame(500, $delivery->lastStatus);
        self::assertNotNull($delivery->failedAt);
        $failed = $this->project->events->of(WebhookFailed::class);
        self::assertCount(1, $failed);
        self::assertSame($endpoint->id, $failed[0]->endpoint?->id);
        self::assertStringContainsString('HTTP 500', $failed[0]->error);

        $this->project->client->queue(new Response(200));
        $resent = $webhooks->resend($delivery->id);
        self::assertSame(Delivery::DELIVERED, $this->project->deliveries()->find($resent->id)?->status);
        self::assertSame(2, $this->project->deliveries()->find($resent->id)->attempts);
        self::assertNull($this->project->deliveries()->find($resent->id)->failedAt);
        $this->project->client->assertRequested('POST https://203.0.113.10/hooks', 2);
    }

    public function testANetworkFailureCountsAsAnAttempt(): void
    {
        $this->project->endpoints()->add('https://203.0.113.10/hooks');
        $this->project->client->queue(static fn(RequestInterface $request): never => throw new class ('Connection refused') extends \RuntimeException implements ClientExceptionInterface {});

        try {
            $this->project->webhooks()->send('payment.succeeded', ['id' => 1]);
            self::fail('The failure reaches the caller on sync://.');
        } catch (DeliveryFailedException $exception) {
            self::assertStringContainsString('Connection refused', $exception->getMessage());
        }
        $delivery = $this->project->deliveries()->find($exception->delivery->id);
        self::assertSame(Delivery::FAILED, $delivery?->status);
        self::assertNull($delivery->lastStatus);
        self::assertSame('Connection refused', $delivery->lastError);
    }

    public function testAnEndpointThatAnswersGoneIsDisabled(): void
    {
        $endpoint = $this->project->endpoints()->add('https://203.0.113.10/hooks');
        $this->project->client->queue(new Response(410));

        $deliveries = $this->project->webhooks()->send('payment.succeeded', ['id' => 1]);

        $delivery = $this->project->deliveries()->find($deliveries[0]->id);
        self::assertSame(Delivery::FAILED, $delivery?->status);
        self::assertSame(410, $delivery->lastStatus);
        self::assertStringContainsString('410 Gone and was disabled', (string) $delivery->lastError);
        self::assertFalse($this->project->endpoints()->find($endpoint->id)?->enabled);
        self::assertCount(1, $this->project->events->of(WebhookFailed::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is disabled');
        $this->project->webhooks()->sendTo($endpoint, 'payment.succeeded', []);
    }

    public function testSendToOneEndpointAndEventNames(): void
    {
        $endpoint = $this->project->endpoints()->add('https://203.0.113.10/hooks', ['refund.*']);
        $this->project->client->queue(new Response(202));

        $delivery = $this->project->webhooks()->sendTo($endpoint->id, 'payment.succeeded', ['id' => 1]);
        self::assertSame(Delivery::DELIVERED, $this->project->deliveries()->find($delivery->id)?->status, 'sendTo() does not look at the subscription.');

        try {
            $this->project->webhooks()->send('payment.*', []);
            self::fail('A pattern is not an event.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"payment.*" is not one', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No webhook endpoint 019a0000-0000-7000-8000-000000000000');
        $this->project->webhooks()->sendTo('019a0000-0000-7000-8000-000000000000', 'payment.succeeded', []);
    }

    public function testTheCommandsResendAndPrune(): void
    {
        $this->project->endpoints()->add('https://203.0.113.10/hooks');
        $this->project->client->queue(new Response(503));
        try {
            $this->project->webhooks()->send('payment.succeeded', ['id' => 1]);
        } catch (DeliveryFailedException $exception) {
            $failed = $exception->delivery;
        }
        self::assertTrue(isset($failed));
        $this->project->client->queue(new Response(200), new Response(200));
        $this->project->webhooks()->send('payment.succeeded', ['id' => 2]);

        $resend = new CommandTester($this->project->application->get(ResendCommand::class));
        self::assertSame(Command::SUCCESS, $resend->execute(['id' => $failed->id]));
        self::assertStringContainsString("Delivery {$failed->id} (payment.succeeded) queued again: delivered after 2 attempt(s).", $resend->getDisplay());
        self::assertSame(Command::FAILURE, $resend->execute(['id' => 'nothing']));
        self::assertStringContainsString('No webhook delivery nothing.', $resend->getDisplay());

        $prune = new CommandTester($this->project->application->get(PruneCommand::class));
        self::assertSame(Command::SUCCESS, $prune->execute(['--days' => '30']));
        self::assertStringContainsString('Removed 0 webhook deliveries older than 30 day(s).', $prune->getDisplay());
        $this->project->clock->modify('+31 days');
        self::assertSame(Command::SUCCESS, $prune->execute([]));
        self::assertStringContainsString('Removed 2 webhook deliveries older than 30 day(s).', $prune->getDisplay());
        self::assertSame(0, (int) $this->project->db()->fetchOne('SELECT COUNT(*) FROM webhook_deliveries'));
        self::assertSame(Command::INVALID, $prune->execute(['--days' => 'soon']));
    }
}
