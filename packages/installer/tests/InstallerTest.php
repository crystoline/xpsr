<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Installer\Tests;

use Crystoline\Xpsr\Installer\Installer;
use Crystoline\Xpsr\Installer\ProjectLocator;
use Crystoline\Xpsr\Installer\Tests\Fixtures\RecordingRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/** Finding the project, and which commands the installer keeps or passes on. */
final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-installer-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/project/app/Http', 0777, true);
        mkdir($this->root . '/other');
        file_put_contents($this->root . '/project/xpsr', "#!/usr/bin/env php\n<?php\n");
        file_put_contents($this->root . '/project/composer.json', '{"require": {"crystoline/xpsr-framework": "^0.5"}}');
        file_put_contents($this->root . '/other/xpsr', '');
        file_put_contents($this->root . '/other/composer.json', '{"require": {"acme/other": "^1"}}');
    }

    protected function tearDown(): void
    {
        foreach (['/project/app/Http', '/project/app', '/project', '/other'] as $directory) {
            array_map('unlink', array_filter((array) glob($this->root . $directory . '/*'), 'is_file'));
            rmdir($this->root . $directory);
        }
        rmdir($this->root);
    }

    public function testTheProjectIsTheNearestDirectoryWithAnXpsrFileThatRequiresTheFramework(): void
    {
        self::assertSame($this->root . '/project', ProjectLocator::find($this->root . '/project/app/Http'));
        self::assertSame($this->root . '/project', ProjectLocator::find($this->root . '/project/'));
        self::assertNull(ProjectLocator::find($this->root . '/other'), 'An xpsr file of something else does not count.');
        self::assertNull(ProjectLocator::find($this->root));
    }

    public function testOtherCommandsRunTheProjectsOwnXpsr(): void
    {
        $runner = new RecordingRunner(failures: ['serve' => 3]);
        $output = new BufferedOutput();

        $exit = Installer::main(['xpsr', 'serve', '--port=3002'], $runner, $this->root . '/project/app/Http', $output);

        self::assertSame(3, $exit, 'The project command\'s exit code is passed on.');
        self::assertSame([['command' => [PHP_BINARY, $this->root . '/project/xpsr', 'serve', '--port=3002'], 'directory' => $this->root . '/project']], $runner->commands);
        self::assertSame('', $output->fetch());
    }

    public function testOutsideAProjectOtherCommandsAreRefused(): void
    {
        $runner = new RecordingRunner();
        $output = new BufferedOutput();

        self::assertSame(1, Installer::main(['xpsr', 'migrate'], $runner, $this->root . '/other', $output));
        self::assertSame([], $runner->commands);
        self::assertStringContainsString('Not in an XPSR project: no xpsr file in ' . $this->root . '/other or above. "xpsr new <name>" creates one.', $output->fetch());
    }

    public function testTheInstallersOwnCommands(): void
    {
        $runner = new RecordingRunner();
        $output = new BufferedOutput();

        self::assertSame(0, Installer::main(['xpsr'], $runner, $this->root . '/project', $output));
        $listing = $output->fetch();
        self::assertStringContainsString('XPSR installer', $listing);
        self::assertStringContainsString('new', $listing);
        self::assertStringContainsString('Create an XPSR project.', $listing);

        self::assertSame(0, Installer::main(['xpsr', '--version'], $runner, $this->root . '/project', $output));
        self::assertStringContainsString('XPSR installer', $output->fetch());

        self::assertSame(2, Installer::main(['xpsr', 'new', '--no-interaction'], $runner, $this->root . '/project', $output), 'new belongs to the installer, even inside a project.');
        self::assertStringContainsString('A project directory is needed', $output->fetch());
        self::assertSame([], $runner->commands);
    }
}
