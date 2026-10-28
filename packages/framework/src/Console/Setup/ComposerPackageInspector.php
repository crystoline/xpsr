<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Setup;

use Composer\InstalledVersions;

/**
 * Reads the application's composer.json and Composer's installed-package data.
 *
 * A package installed only through require-dev does not count: it would be missing from a
 * production install made with --no-dev.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
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

    public function releaseConstraint(): string
    {
        $package = 'crystoline/xpsr-framework';
        $version = InstalledVersions::isInstalled($package) ? InstalledVersions::getVersion($package) : null;

        return self::constraintFor($version);
    }

    /** @param string|null $version a normalized Composer version, such as 0.1.0.0 or 0.1.9999999.9999999-dev */
    public static function constraintFor(?string $version): string
    {
        if ($version === null || !preg_match('/\A(\d+)\.(\d+)\./', $version, $match)) {
            return '*';
        }

        return "^{$match[1]}.{$match[2]}" . (str_ends_with($version, '-dev') ? '@dev' : '');
    }
}
