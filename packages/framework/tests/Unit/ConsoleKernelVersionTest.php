<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Composer\InstalledVersions;
use Crystoline\Console\ConsoleKernel;
use PHPUnit\Framework\TestCase;

final class ConsoleKernelVersionTest extends TestCase
{
    public function testReportsTheInstalledFrameworkVersionWithoutALeadingV(): void
    {
        $package = 'crystoline/xpsr-framework';
        $recorded = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        $expected = $recorded === null || $recorded === '' ? 'development' : ltrim($recorded, 'v');

        self::assertSame($expected, ConsoleKernel::version());
        self::assertStringStartsNotWith('v', ConsoleKernel::version());
        if ($recorded !== null) {
            self::assertNotSame('development', ConsoleKernel::version(), 'An installed framework reports its real version.');
        }
    }
}
