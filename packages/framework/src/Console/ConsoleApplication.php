<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console;

use Crystoline\Xpsr\Console\Event\CommandFinished;
use Crystoline\Xpsr\Console\Event\CommandStarting;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Symfony's console application, dispatching CommandStarting and CommandFinished through the
 * application's PSR-14 dispatcher around each command.
 *
 * @internal Built by ConsoleKernel for each run.
 */
final class ConsoleApplication extends Application
{
    public function __construct(string $name, string $version, private readonly ?EventDispatcherInterface $events)
    {
        parent::__construct($name, $version);
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if ($this->events === null) {
            return parent::doRunCommand($command, $input, $output);
        }
        $name = (string) $command->getName();
        $this->events->dispatch(new CommandStarting($name, $input, $output));
        try {
            $code = parent::doRunCommand($command, $input, $output);
        } catch (Throwable $exception) {
            $this->events->dispatch(new CommandFinished($name, $input, $output, 1, $exception));
            throw $exception;
        }
        $this->events->dispatch(new CommandFinished($name, $input, $output, $code));

        return $code;
    }
}
