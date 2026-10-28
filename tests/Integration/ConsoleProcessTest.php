<?php

declare(strict_types=1);

namespace Tests\Integration;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConsoleProcessTest extends TestCase
{
    public function testEntryPointRunsFromAnotherDirectoryAndPropagatesExitStatus(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root . '/xpsr', 'about', '--json', '--no-interaction'], sys_get_temp_dir(), timeout: 10);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        $about = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($root, $about['root']);
        self::assertSame('XPSR', $about['framework']);
        // In this repository the framework is installed as 0.1.x-dev; a released install reports e.g. 0.1.1.
        $version = ltrim((string) InstalledVersions::getPrettyVersion('crystoline/xpsr-framework'), 'v');
        self::assertSame($version, $about['version']);
        $banner = new Process([PHP_BINARY, $root . '/xpsr', '--version', '--no-interaction'], sys_get_temp_dir(), timeout: 10);
        self::assertSame(0, $banner->run());
        self::assertStringContainsString("XPSR {$version}", $banner->getOutput());
        $invalid = new Process([PHP_BINARY, $root . '/xpsr', 'missing:command', '--no-interaction'], sys_get_temp_dir(), timeout: 10);
        self::assertSame(1, $invalid->run());
        self::assertSame('', $invalid->getOutput());
        self::assertStringContainsString('missing', $invalid->getErrorOutput());
    }

    public function testBootstrapFailureUsesStderrAndFailureExitCode(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/xpsr', 'about'], sys_get_temp_dir(), ['APP_ENV' => '../invalid'], timeout: 10);
        self::assertSame(1, $process->run());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('XPSR could not start:', $process->getErrorOutput());
        self::assertStringContainsString('APP_ENV', $process->getErrorOutput());
    }
}
