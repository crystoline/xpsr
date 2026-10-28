<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Setup;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
interface PackageInspectorInterface
{
    /** Whether the application requires the package for production and it is installed. */
    public function isRequired(string $package): bool;

    /**
     * The Composer constraint for XPSR packages that matches the installed framework, because
     * all XPSR packages are released together: "^0.1" for a 0.1.x release, "^0.1@dev" for a
     * 0.1 development version, or "*" when the version cannot be determined.
     */
    public function releaseConstraint(): string;
}
