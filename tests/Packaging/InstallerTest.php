<?php

declare(strict_types=1);

namespace Tests\Packaging;

use Crystoline\Xpsr\Console\Setup\BootstrapCheck;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * "xpsr new" for real: the installer creates a project from the exported skeleton and the
 * monorepo's packages, through Composer, and the project's commands then run through the
 * installer from inside the project. Needs Composer; runs with the packaging group.
 */
#[Group('packaging')]
final class InstallerTest extends TestCase
{
    private const REPOSITORY = __DIR__ . '/../..';

    private string $composer;
    private string $work;

    protected function setUp(): void
    {
        $binary = getenv('COMPOSER_BINARY') ?: (new ExecutableFinder())->find('composer');
        if (!is_string($binary) || $binary === '') {
            self::markTestSkipped('Composer is not available.');
        }
        $this->composer = $binary;
        $this->work = TemporaryDirectory::create('installer');
    }

    protected function tearDown(): void
    {
        if (isset($this->work)) {
            TemporaryDirectory::remove($this->work);
        }
    }

    /** @param list<string> $command */
    private function runCommand(array $command, string $directory): string
    {
        $process = new Process($command, $directory, BootstrapCheck::environment(), timeout: 900);
        $process->run();
        self::assertTrue(
            $process->isSuccessful(),
            sprintf("Command failed (%d): %s\n%s\n%s", $process->getExitCode(), implode(' ', $command), $process->getOutput(), $process->getErrorOutput()),
        );

        return $process->getOutput() . $process->getErrorOutput();
    }

    public function testNewCreatesAWorkingProject(): void
    {
        $skeleton = $this->work . '/skeleton';
        $this->runCommand([PHP_BINARY, self::REPOSITORY . '/scripts/export-skeleton.php', $skeleton], $this->work);
        $root = json_decode((string) file_get_contents(self::REPOSITORY . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $packages = $root['repositories'][0];
        $packages['url'] = realpath(self::REPOSITORY) . '/packages/*';
        $packages['options']['symlink'] = false;
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        $project = $this->work . '/shop';
        $installer = [PHP_BINARY, self::REPOSITORY . '/vendor/bin/xpsr'];

        $output = $this->runCommand([
            ...$installer, 'new', $project, '--router=fastroute', '--preset=api', '--no-interaction', '--release=0.5.x-dev',
            '--repository=' . json_encode(['type' => 'path', 'url' => $skeleton, 'options' => ['symlink' => false, 'versions' => ['crystoline/xpsr' => '0.5.x-dev']]], $flags),
            '--repository=' . json_encode($packages, $flags),
            '--composer=' . $this->composer,
        ], $this->work);

        self::assertStringContainsString('Bootstrap check passed', $output, 'The project\'s own setup ran.');
        self::assertStringContainsString('Created shop in ' . $project, $output);
        self::assertFileExists($project . '/.env');
        self::assertFalse(is_link($project . '/vendor/crystoline/xpsr-framework'));
        self::assertSame(['hello'], array_column(json_decode($this->runCommand([PHP_BINARY, $project . '/xpsr', 'route:list', '--json'], $project), true), 'name'), 'The api preset loads routes/api.php only.');

        // Inside the project, the installer runs the project's commands.
        $about = json_decode($this->runCommand([...$installer, 'about', '--json'], $project . '/app'), true);
        self::assertSame('fastroute', $about['router']);
    }
}
