<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Event;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dispatched through the application's PSR-14 dispatcher before a console command runs,
 * including a command run by another one, such as a scheduled command under schedule:run.
 */
final class CommandStarting
{
    public function __construct(
        public readonly string $command,
        public readonly InputInterface $input,
        public readonly OutputInterface $output,
    ) {}
}
