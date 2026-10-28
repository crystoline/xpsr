<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console;

use InvalidArgumentException;

final class CommandRegistry
{
    /** Lowercase words separated by colons; digits, underscores, and hyphens may follow each first letter. */
    public const NAME_PATTERN = '/\A[a-z][a-z0-9_-]*(?::[a-z][a-z0-9_-]*)*\z/';

    /** @var array<string, array{service: string, description: string, aliases: list<string>}> */
    private array $commands = [];
    /** @var array<string, true> */
    private array $names = ['list' => true, 'help' => true, 'completion' => true, '_complete' => true];

    /** @param array<array-key, mixed> $aliases */
    public function register(string $name, string $service, string $description = '', array $aliases = []): void
    {
        $names = [$name, ...array_values($aliases)];
        if ($service === '') {
            throw new InvalidArgumentException('A command service ID cannot be empty.');
        }
        $seen = [];
        foreach ($names as $candidate) {
            if (!is_string($candidate) || !preg_match(self::NAME_PATTERN, $candidate)) {
                throw new InvalidArgumentException('Command names and aliases must use lowercase words separated by colons.');
            }
            if (isset($this->names[$candidate]) || isset($seen[$candidate])) {
                throw new InvalidArgumentException("Duplicate or reserved command name: {$candidate}");
            }
            $seen[$candidate] = true;
        }
        $this->names += $seen;
        $this->commands[$name] = ['service' => $service, 'description' => $description, 'aliases' => array_values($aliases)];
    }

    /** @return array<string, array{service: string, description: string, aliases: list<string>}> */
    public function all(): array
    {
        return $this->commands;
    }
}
