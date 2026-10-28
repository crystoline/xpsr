<?php

declare(strict_types=1);

namespace Crystoline\Console;

use Composer\InstalledVersions;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

final class ConsoleKernel
{
    /** @deprecated since 0.1.1. Use version(), which reports the installed release. */
    public const VERSION = 'development';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly CommandRegistry $commands,
    ) {}

    /**
     * The installed crystoline/xpsr-framework version, such as "0.1.1" or "0.1.x-dev", as Composer
     * recorded it, without a leading "v". "development" when Composer has no record of it.
     */
    public static function version(): string
    {
        $package = 'crystoline/xpsr-framework';
        $version = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;

        return $version === null || $version === '' ? 'development' : ltrim($version, 'v');
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        // Symfony keeps per-invocation state. Build a fresh application for each run.
        $console = new Application('XPSR', self::version());
        $console->setAutoExit(false);
        $console->setCatchErrors(true);
        foreach ($this->commands->all() as $name => $definition) {
            $console->addCommand(new LazyCommand(
                $name,
                $definition['aliases'],
                $definition['description'],
                false,
                function () use ($definition): Command {
                    $command = $this->container->get($definition['service']);
                    if (!$command instanceof Command) {
                        throw new UnexpectedValueException("Command service \"{$definition['service']}\" must extend Symfony Console Command.");
                    }
                    return $command;
                },
            ));
        }
        return $console->run($input, $output);
    }
}
