<?php

declare(strict_types=1);

namespace Tests\Integration;

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
