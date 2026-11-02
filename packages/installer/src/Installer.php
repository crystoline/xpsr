<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The xpsr command outside a project. "xpsr new" creates a project; every other command, such
 * as "xpsr serve" or "xpsr migrate", runs the nearest project's own xpsr with it, so "php xpsr"
 * loses its prefix. "xpsr list", "help", "--version", and the other options belong to the
 * installer itself.
 */
final class Installer
{
    private const OWN = ['new', 'list', 'help', 'completion'];

    /**
     * @param list<string> $argv
     * @return int the exit code
     */
    public static function main(array $argv, ?RunnerInterface $runner = null, ?string $directory = null, ?OutputInterface $output = null): int
    {
        $output ??= new ConsoleOutput();
        $runner ??= new ProcessRunner($output);
        $arguments = array_slice($argv, 1);
        $first = $arguments[0] ?? null;
        if ($first !== null && !str_starts_with($first, '-') && !in_array($first, self::OWN, true)) {
            $project = ProjectLocator::find($directory ?? (string) getcwd());
            if ($project === null) {
                $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $errors->writeln(sprintf('<error>Not in an XPSR project: no xpsr file in %s or above. "xpsr new <name>" creates one.</error>', $directory ?? getcwd()));

                return 1;
            }

            return $runner->run([PHP_BINARY, $project . '/xpsr', ...$arguments], $project);
        }
        $console = self::console($runner);
        $console->setAutoExit(false);

        return $console->run(new ArgvInput($argv), $output);
    }

    public static function console(RunnerInterface $runner): Application
    {
        $console = new Application('XPSR installer', self::version());
        $console->addCommand(new NewCommand($runner));

        return $console;
    }

    public static function version(): string
    {
        $package = 'crystoline/xpsr-installer';

        return InstalledVersions::isInstalled($package) ? (InstalledVersions::getPrettyVersion($package) ?? 'development') : 'development';
    }
}
