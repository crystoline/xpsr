<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer;

/** Runs the programs the installer drives: Composer, a project's xpsr, and git. */
interface RunnerInterface
{
    /**
     * Runs a command with the user's terminal: its output shows as it is produced, and it can ask
     * questions. Returns its exit code.
     *
     * @param list<string> $command
     */
    public function run(array $command, ?string $directory = null): int;

    /**
     * Runs a command quietly and returns its output, or null when it fails.
     *
     * @param list<string> $command
     */
    public function capture(array $command, ?string $directory = null): ?string;

    /** The path of a program on the PATH, or null when there is none. */
    public function find(string $program): ?string;
}
