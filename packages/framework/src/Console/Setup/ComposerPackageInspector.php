<?php

declare(strict_types=1);

namespace Crystoline\Console\Setup;

use Composer\InstalledVersions;

/**
 * Reads the application's composer.json and Composer's installed-package data.
 *
 * A package installed only through require-dev does not count: it would be missing from a
 * production install made with --no-dev.
 */
final class ComposerPackageInspector implements PackageInspectorInterface
{
    public function __construct(private readonly string $rootDir) {}

    public function isRequired(string $package): bool
    {
        $manifest = @file_get_contents($this->rootDir . '/composer.json');
        $data = $manifest === false ? null : json_decode($manifest, true);
        $require = is_array($data) && is_array($data['require'] ?? null) ? $data['require'] : [];

        return array_key_exists($package, $require) && InstalledVersions::isInstalled($package, false);
    }
}
