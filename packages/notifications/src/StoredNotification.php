<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;

/**
 * A notification in the Inbox. As JSON it has the fields and date format of a Laravel
 * DatabaseNotification: {"id", "type", "data", "read_at", "created_at"}, dates in UTC as
 * "2026-10-30T12:00:00.000000Z".
 */
final class StoredNotification implements JsonSerializable
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
        public readonly ?DateTimeImmutable $readAt,
        public readonly ?DateTimeImmutable $createdAt,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /** @return array{id: string, type: string, data: array<array-key, mixed>, read_at: string|null, created_at: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'read_at' => self::json($this->readAt),
            'created_at' => self::json($this->createdAt),
        ];
    }

    private static function json(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
