<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use DateTimeImmutable;

/** One webhook to one endpoint, and how its sending went: a row of webhooks.deliveries_table. */
final class Delivery
{
    public const PENDING = 'pending';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';

    /** @param array<array-key, mixed> $payload the event's data */
    public function __construct(
        public readonly string $id,
        public readonly string $endpointId,
        public readonly string $event,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly string $status,
        public readonly ?int $lastStatus,
        public readonly ?string $lastError,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $deliveredAt = null,
        public readonly ?DateTimeImmutable $failedAt = null,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status === self::DELIVERED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
