<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer;

/** Finds the XPSR project a directory is in: the nearest directory above it with an xpsr file and a composer.json that requires the framework. */
final class ProjectLocator
{
    public static function find(string $directory): ?string
    {
        $directory = rtrim($directory, '/\\') ?: $directory;
        while (true) {
            if (self::isProject($directory)) {
                return $directory;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    public static function isProject(string $directory): bool
    {
        if (!is_file($directory . '/xpsr') || !is_file($directory . '/composer.json')) {
            return false;
        }

        return str_contains((string) file_get_contents($directory . '/composer.json'), 'crystoline/xpsr-framework');
    }
}
