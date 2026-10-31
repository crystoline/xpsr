<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> "level: message", without the placeholders filled in */
    public function lines(): array
    {
        return array_map(static fn(array $record): string => $record['level'] . ': ' . $record['message'], $this->records);
    }
}
