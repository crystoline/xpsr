<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer\Tests\Fixtures;

use Crystoline\Xpsr\Installer\RunnerInterface;

/** Records the commands instead of running them; a command whose text contains a failing word returns that word's exit code. */
final class RecordingRunner implements RunnerInterface
{
    /** @var list<array{command: list<string>, directory: string|null}> */
    public array $commands = [];

    /**
     * @param array<string, string> $programs what find() knows, by name
     * @param array<string, int> $failures exit codes by a word the failing command contains
     */
    public function __construct(
        private readonly array $programs = ['composer' => '/usr/bin/composer', 'git' => '/usr/bin/git'],
        private readonly array $failures = [],
        private readonly ?string $captured = 'XPSR 0.5.0',
    ) {}

    public function run(array $command, ?string $directory = null): int
    {
        $this->commands[] = ['command' => $command, 'directory' => $directory];
        $text = implode(' ', $command);
        foreach ($this->failures as $word => $exit) {
            if (str_contains($text, $word)) {
                return $exit;
            }
        }

        return 0;
    }

    public function capture(array $command, ?string $directory = null): ?string
    {
        $this->commands[] = ['command' => $command, 'directory' => $directory];

        return $this->captured;
    }

    public function find(string $program): ?string
    {
        return $this->programs[$program] ?? null;
    }

    /** @return list<string> the commands as text */
    public function texts(): array
    {
        return array_map(static fn(array $run): string => implode(' ', $run['command']), $this->commands);
    }
}
