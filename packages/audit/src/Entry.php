<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use DateTimeImmutable;

/** One row of the log: what happened, to what, by whom, from where, and the values that changed. */
final class Entry
{
    /**
     * @param array<string, array{mixed, mixed}> $changes field => [before, after]
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $id,
        public readonly DateTimeImmutable $occurredAt,
        public readonly string $action,
        public readonly ?AuditKey $subject,
        public readonly ?AuditKey $actor,
        public readonly string $source,
        public readonly ?string $requestId,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly array $changes,
        public readonly array $context,
    ) {}

    /** The value a field had before, or null. */
    public function before(string $field): mixed
    {
        return $this->changes[$field][0] ?? null;
    }

    /** The value a field has after, or null. */
    public function after(string $field): mixed
    {
        return $this->changes[$field][1] ?? null;
    }
}
