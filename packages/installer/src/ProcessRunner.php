<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs programs with Symfony Process. With a terminal, a command gets it as its own (TTY), so
 * Composer shows progress and a project's commands can ask questions; without one, output is
 * passed through as it arrives.
 */
final class ProcessRunner implements RunnerInterface
{
    public function __construct(private readonly OutputInterface $output) {}

    public function run(array $command, ?string $directory = null): int
    {
        $process = new Process($command, $directory, timeout: null);
        if (Process::isTtySupported()) {
            $process->setTty(true);

            return $process->run();
        }
        $errors = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

        return $process->run(function (string $type, string $buffer) use ($errors): void {
            ($type === Process::ERR ? $errors : $this->output)->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
    }

    public function capture(array $command, ?string $directory = null): ?string
    {
        $process = new Process($command, $directory, timeout: 60);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    public function find(string $program): ?string
    {
        return (new ExecutableFinder())->find($program);
    }
}
