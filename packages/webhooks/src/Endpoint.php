<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use DateTimeImmutable;

/** A subscriber: where webhooks go, which events it wants, and the secret its deliveries are signed with. */
final class Endpoint
{
    /** @param list<string> $events event names, "payment.*" for a family, or "*" for all */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly array $events,
        public readonly string $secret,
        public readonly bool $enabled,
        public readonly ?string $label,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $disabledAt = null,
    ) {}

    public function subscribes(string $event): bool
    {
        foreach ($this->events as $pattern) {
            if ($pattern === '*' || $pattern === $event) {
                return true;
            }
            if (str_ends_with($pattern, '*') && str_starts_with($event, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
