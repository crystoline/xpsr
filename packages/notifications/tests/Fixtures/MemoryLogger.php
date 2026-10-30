<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: mixed, message: string, context: array<array-key, mixed>}> the records whose message starts with $prefix */
    public function starting(string $prefix): array
    {
        return array_values(array_filter($this->records, static fn(array $record): bool => str_starts_with($record['message'], $prefix)));
    }
}
