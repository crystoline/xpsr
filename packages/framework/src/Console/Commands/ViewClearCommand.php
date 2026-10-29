<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Application;
use Closure;
use Crystoline\Xpsr\Configuration;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties app.view_cache_dir, keeping the directory itself. The engines compile changed templates
 * again on their own; Twig and Blade notice a change by file time, so deploys that keep the files'
 * original times (unpacking an archive, rsync -a) need this command.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ViewClearCommand extends Command
{
    /** @var Closure(string): bool */
    private readonly Closure $unlink;

    /** @param (Closure(string): bool)|null $unlink replaces PHP's unlink() in tests */
    public function __construct(
        private readonly Configuration $config,
        private readonly Application $application,
        ?Closure $unlink = null,
    ) {
        parent::__construct('view:clear');
        $this->unlink = $unlink ?? static fn(string $path): bool => @unlink($path);
    }

    protected function configure(): void
    {
        $this->setDescription('Remove compiled view templates.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $setting = $this->config->get('app.view_cache_dir', false);
        if (!is_string($setting) || $setting === '') {
            $output->writeln('No view cache is configured (app.view_cache_dir is false).');

            return self::SUCCESS;
        }
        $shown = OutputFormatter::escape($this->shorten($setting));
        $directory = realpath($setting);
        if ($directory === false || !is_dir($directory)) {
            $output->writeln("The view cache in {$shown} is empty.");

            return self::SUCCESS;
        }
        // A mistyped setting must not empty the application.
        foreach ([$this->application->getRootDir(), $this->config->get('app.view_dir')] as $kept) {
            $path = is_string($kept) ? realpath($kept) : false;
            if ($path !== false && self::contains($directory, $path)) {
                $errors->error("The view cache ({$this->shorten($setting)}) contains {$path}, so it was not emptied. Set app.view_cache_dir to a directory of its own.");

                return self::FAILURE;
            }
        }

        [$removed, $failed] = $this->removeContents($directory);
        if ($failed !== []) {
            $errors->error(sprintf(
                'Could not remove %d file(s) from the view cache in %s, such as %s. Run view:clear as the user that owns them, such as the web server user.',
                count($failed),
                $this->shorten($setting),
                $failed[0],
            ));

            return self::FAILURE;
        }
        $output->writeln("Removed {$removed} file(s) from the view cache in {$shown}.");

        return self::SUCCESS;
    }

    /**
     * Removes everything below $directory without following links.
     *
     * @return array{int, list<string>} the number of files removed, and the paths that could not be
     */
    private function removeContents(string $directory): array
    {
        $removed = 0;
        $failed = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                // A directory that still holds a file that could not be removed stays as well.
                @rmdir($path);
                continue;
            }
            if (($this->unlink)($path)) {
                ++$removed;
            } else {
                $failed[] = $path;
            }
        }

        return [$removed, $failed];
    }

    private static function contains(string $directory, string $path): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $directory);
    }

    /** The path relative to the application root when it is inside it, as other commands show it. */
    private function shorten(string $path): string
    {
        $root = rtrim($this->application->getRootDir(), '/\\') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
