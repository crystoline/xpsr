<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A copy of the application skeleton in a temporary directory whose path contains spaces.
 *
 * vendor/ is a symbolic link to this repository's vendor directory, so App\ classes and
 * installed-package data come from this repository.
 */
final class SkeletonProject
{
    public static function create(): string
    {
        $project = dirname(__DIR__, 2);
        $root = TemporaryDirectory::create('skeleton project');
        if (!@symlink($project . '/vendor', $root . '/vendor')) {
            TemporaryDirectory::remove($root);
            Assert::markTestSkipped('Skeleton project tests need symbolic links.');
        }
        foreach (['bootstrap', 'config', 'routes', 'public', 'views'] as $directory) {
            self::copyDirectory($project . '/' . $directory, $root . '/' . $directory);
        }
        foreach (['xpsr', '.env.example', 'composer.json'] as $file) {
            copy($project . '/' . $file, $root . '/' . $file);
        }

        return $root;
    }

    private static function copyDirectory(string $source, string $target): void
    {
        mkdir($target);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $destination = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            $item->isDir() ? mkdir($destination) : copy($item->getPathname(), $destination);
        }
    }
}
