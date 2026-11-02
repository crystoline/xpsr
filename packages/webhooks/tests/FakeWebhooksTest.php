<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests;

use Crystoline\Xpsr\Webhooks\Delivery;
use Crystoline\Xpsr\Webhooks\Testing\FakeWebhooks;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class FakeWebhooksTest extends TestCase
{
    public function testRecordsAndAsserts(): void
    {
        $webhooks = new FakeWebhooks();
        $webhooks->assertNothingSent();

        self::assertSame([], $webhooks->send('payment.succeeded', ['id' => 7]));
        $delivery = $webhooks->sendTo('019a0000-0000-7000-8000-000000000001', 'payment.succeeded', ['id' => 8]);
        self::assertInstanceOf(Delivery::class, $delivery);
        self::assertSame(Delivery::PENDING, $delivery->status);
        $webhooks->send('refund.issued', ['id' => 9]);

        $webhooks->assertSent('payment.succeeded');
        $webhooks->assertSent('payment.succeeded', null, 2);
        $webhooks->assertSent('payment.succeeded', static fn(array $data): bool => $data['id'] === 8, 1);
        $webhooks->assertSent('payment.succeeded', static fn(array $data, mixed $endpoint): bool => $endpoint === '019a0000-0000-7000-8000-000000000001');
        $webhooks->assertNotSent('invoice.created');
        $webhooks->assertNotSent('refund.issued', static fn(array $data): bool => $data['id'] === 1);
        self::assertSame(['id' => 9], $webhooks->sent('refund.issued')[0]['data']);
        self::assertCount(3, $webhooks->sent());

        try {
            $webhooks->assertSent('invoice.created');
            self::fail('Not sent.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('The webhook "invoice.created" was not sent', $failure->getMessage());
            self::assertStringContainsString('Sent: payment.succeeded, payment.succeeded, refund.issued.', $failure->getMessage());
        }
        try {
            $webhooks->assertSent('refund.issued', null, 2);
            self::fail('Sent once, not twice.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('sent 1 time(s), not 2', $failure->getMessage());
        }

        $webhooks->clear();
        $webhooks->assertNothingSent();
    }
}
