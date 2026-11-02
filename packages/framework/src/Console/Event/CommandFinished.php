<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Event;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Dispatched through the application's PSR-14 dispatcher after a console command ran, with
 * its exit code, or with the exception it threw and exit code 1, before the console reports it.
 */
final class CommandFinished
{
    public function __construct(
        public readonly string $command,
        public readonly InputInterface $input,
        public readonly OutputInterface $output,
        public readonly int $exitCode,
        public readonly ?Throwable $exception = null,
    ) {}
}
