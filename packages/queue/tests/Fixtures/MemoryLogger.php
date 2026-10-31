<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests\Fixtures;

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

    /** @return list<string> "level: message", with the {placeholders} filled in */
    public function lines(): array
    {
        return array_map(static function (array $record): string {
            $replacements = [];
            foreach ($record['context'] as $key => $value) {
                $replacements['{' . $key . '}'] = is_scalar($value) ? (string) $value : '';
            }

            return $record['level'] . ': ' . strtr($record['message'], $replacements);
        }, $this->records);
    }
}
