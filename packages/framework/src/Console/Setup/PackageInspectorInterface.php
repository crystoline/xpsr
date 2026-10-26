<?php

declare(strict_types=1);

namespace Crystoline\Console\Setup;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
interface PackageInspectorInterface
{
    /** Whether the application requires the package for production and it is installed. */
    public function isRequired(string $package): bool;
}
