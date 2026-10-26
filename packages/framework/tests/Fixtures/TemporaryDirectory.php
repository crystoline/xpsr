<?php

declare(strict_types=1);

namespace Crystoline\Tests\Fixtures;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TemporaryDirectory
{
    /** Create a uniquely named directory. The name contains a space to catch quoting bugs. */
    public static function create(string $label): string
    {
        $path = sys_get_temp_dir() . '/xpsr ' . $label . ' ' . bin2hex(random_bytes(8));
        mkdir($path, 0777, true);

        return $path;
    }

    /** Remove a directory tree without following symbolic links. */
    public static function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $file = $item->getPathname();
            is_dir($file) && !is_link($file) ? rmdir($file) : unlink($file);
        }
        rmdir($path);
    }

    /** @return list<string> relative paths of every file and link below $path */
    public static function files(string $path): array
    {
        $files = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $files[] = substr($item->getPathname(), strlen($path) + 1);
        }
        sort($files);

        return $files;
    }
}
