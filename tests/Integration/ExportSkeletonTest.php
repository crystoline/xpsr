<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Tests\Fixtures\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ExportSkeletonTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = TemporaryDirectory::create('export');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->work);
    }

    /** @return array{int, string} */
    private function export(string ...$arguments): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/export-skeleton.php', ...$arguments]);
        $process->run();

        return [(int) $process->getExitCode(), $process->getErrorOutput()];
    }

    /** @return array<string, mixed> */
    private function manifest(string $directory): array
    {
        return json_decode((string) file_get_contents($directory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testDevelopmentExportKeepsDevelopmentConstraintsAndDropsMonorepoParts(): void
    {
        $target = $this->work . '/skeleton';
        self::assertSame(0, $this->export($target)[0]);

        $manifest = $this->manifest($target);
        self::assertSame('^0.1@dev', $manifest['require']['crystoline/xpsr-framework']);
        self::assertSame('^0.1@dev', $manifest['require']['crystoline/xpsr-router-fastroute']);
        self::assertArrayNotHasKey('repositories', $manifest);
        self::assertSame(['psr-4' => ['Tests\\' => 'tests/']], $manifest['autoload-dev']);
        self::assertSame(['filp/whoops', 'phpunit/phpunit'], array_keys($manifest['require-dev']));
        self::assertSame(['dev-master' => '0.1.x-dev'], $manifest['extra']['branch-alias']);
        foreach (['test:packaging', 'analyse', 'style', 'check'] as $script) {
            self::assertArrayNotHasKey($script, $manifest['scripts']);
        }
        self::assertArrayHasKey('test', $manifest['scripts']);

        foreach (['xpsr', 'phpunit.xml', 'tests/TestCase.php', 'tests/Application/ExampleTest.php', 'public/index.php', '.env.example'] as $file) {
            self::assertFileExists($target . '/' . $file);
        }
        foreach (['composer.lock', 'packages', 'docs', 'scripts', 'tests/Integration', 'tests/Packaging', '.github', 'vendor'] as $path) {
            self::assertFileDoesNotExist($target . '/' . $path, "{$path} is monorepo-only.");
        }
        self::assertTrue(is_executable($target . '/xpsr'), 'The executable keeps its mode.');
    }

    public function testReleaseExportRequiresTheReleaseRange(): void
    {
        foreach (['0.1.0' => '^0.1', '0.1.7' => '^0.1', '1.2.3' => '^1.2'] as $version => $constraint) {
            $target = $this->work . '/release-' . $version;
            self::assertSame(0, $this->export($target, '--release=' . $version)[0]);
            $require = $this->manifest($target)['require'];
            self::assertSame($constraint, $require['crystoline/xpsr-framework'], $version);
            self::assertSame($constraint, $require['crystoline/xpsr-router-fastroute'], $version);
            self::assertSame('^1.8.2', $require['nyholm/psr7'], 'Other packages keep their constraints.');
        }
    }

    public function testInvalidUsageChangesNothing(): void
    {
        [$status, $errors] = $this->export($this->work . '/bad', '--release=v0.1');
        self::assertSame(2, $status);
        self::assertStringContainsString('--release', $errors);
        self::assertDirectoryDoesNotExist($this->work . '/bad');

        mkdir($this->work . '/not-empty');
        file_put_contents($this->work . '/not-empty/keep.txt', 'keep');
        self::assertSame(2, $this->export($this->work . '/not-empty')[0]);
        self::assertSame(['keep.txt'], TemporaryDirectory::files($this->work . '/not-empty'));
        self::assertSame(2, $this->export()[0]);
    }
}
