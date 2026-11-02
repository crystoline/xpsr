<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Testing;

use Closure;
use Crystoline\Xpsr\Webhooks\Delivery;
use Crystoline\Xpsr\Webhooks\Endpoint;
use Crystoline\Xpsr\Webhooks\Uuid;
use Crystoline\Xpsr\Webhooks\WebhooksInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Assert;

/**
 * Keeps the webhooks the application would have sent instead of sending them, with assertions:
 *
 *     $this->swap(WebhooksInterface::class, $webhooks = new FakeWebhooks());
 *     ...
 *     $webhooks->assertSent('payment.succeeded', fn(array $data): bool => $data['id'] === 7);
 */
final class FakeWebhooks implements WebhooksInterface
{
    /** @var list<array{event: string, data: array<array-key, mixed>, endpoint: Endpoint|string|null}> */
    private array $sent = [];

    public function send(string $event, array $data): array
    {
        $this->sent[] = ['event' => $event, 'data' => $data, 'endpoint' => null];

        return [];
    }

    public function sendTo(Endpoint|string $endpoint, string $event, array $data): Delivery
    {
        $this->sent[] = ['event' => $event, 'data' => $data, 'endpoint' => $endpoint];

        return new Delivery(Uuid::next(), $endpoint instanceof Endpoint ? $endpoint->id : $endpoint, $event, $data, 0, Delivery::PENDING, null, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function resend(string $deliveryId): Delivery
    {
        return new Delivery($deliveryId, '', '', [], 0, Delivery::PENDING, null, null, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * What was sent, newest last, each with its event, data, and the endpoint of sendTo().
     *
     * @return list<array{event: string, data: array<array-key, mixed>, endpoint: Endpoint|string|null}>
     */
    public function sent(?string $event = null): array
    {
        return array_values(array_filter($this->sent, static fn(array $sent): bool => $event === null || $sent['event'] === $event));
    }

    /**
     * @param (Closure(array<array-key, mixed>, Endpoint|string|null): bool)|null $check a condition on the data (and endpoint) of a sending that counts
     * @param int|null $times how many sendings must match; null for one or more
     */
    public function assertSent(string $event, ?Closure $check = null, ?int $times = null): void
    {
        $matching = array_filter($this->sent($event), static fn(array $sent): bool => $check === null || $check($sent['data'], $sent['endpoint']));
        $count = count($matching);
        $ok = $times === null ? $count > 0 : $count === $times;
        Assert::assertTrue($ok, sprintf(
            'The webhook "%s" was %s %s. Sent: %s.',
            $event,
            $times === null ? 'not sent' : "sent {$count} time(s), not {$times}",
            $check === null ? '' : 'matching the check',
            $this->sent === [] ? 'nothing' : implode(', ', array_map(static fn(array $sent): string => $sent['event'], $this->sent)),
        ));
    }

    public function assertNotSent(string $event, ?Closure $check = null): void
    {
        $matching = array_filter($this->sent($event), static fn(array $sent): bool => $check === null || $check($sent['data'], $sent['endpoint']));
        Assert::assertCount(0, $matching, "The webhook \"{$event}\" was sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Webhooks were sent: ' . implode(', ', array_map(static fn(array $sent): string => $sent['event'], $this->sent)) . '.');
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
