<?php

declare(strict_types=1);

namespace Tests\Packaging;

use Crystoline\Console\Setup\BootstrapCheck;
use Crystoline\Tests\Fixtures\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Installs the packages the way users will: each package on its own, and the skeleton through
 * composer create-project. Local packages come from path repositories without symbolic links.
 * Third-party dependencies come from Packagist, so these tests need network access.
 *
 * Run: composer test:packaging
 */
#[Group('packaging')]
final class PackagesTest extends TestCase
{
    private const REPOSITORY = __DIR__ . '/../..';

    /** @var list<string> */
    private array $composer = [];
    private string $work;

    protected function setUp(): void
    {
        $binary = getenv('COMPOSER_BINARY') ?: (new ExecutableFinder())->find('composer');
        if (!is_string($binary) || $binary === '') {
            self::markTestSkipped('Composer is not available.');
        }
        $this->composer = preg_match('/\.(?:phar|php)\z/', $binary) ? [PHP_BINARY, $binary] : [$binary];
        $this->work = TemporaryDirectory::create('packaging');
    }

    protected function tearDown(): void
    {
        if (isset($this->work)) {
            TemporaryDirectory::remove($this->work);
        }
    }

    /** @param list<string> $command */
    private function runCommand(array $command, string $directory, array $environment = []): string
    {
        $process = new Process($command, $directory, $environment + BootstrapCheck::environment(), timeout: 900);
        $process->run();
        self::assertTrue(
            $process->isSuccessful(),
            sprintf("Command failed (%d): %s\n%s\n%s", $process->getExitCode(), implode(' ', $command), $process->getOutput(), $process->getErrorOutput()),
        );

        return $process->getOutput();
    }

    /** @param list<string> $arguments */
    private function composer(array $arguments, string $directory): string
    {
        // Commands that download accept --no-progress; others such as config and validate do not.
        $progress = in_array($arguments[0], ['install', 'create-project', 'require', 'update'], true) ? ['--no-progress'] : [];

        return $this->runCommand([...$this->composer, ...$arguments, '--no-interaction', ...$progress], $directory);
    }

    /** The monorepo's path repository, copying packages instead of linking them. */
    private function packagesRepository(): string
    {
        $root = json_decode((string) file_get_contents(self::REPOSITORY . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $repository = $root['repositories'][0];
        $repository['url'] = realpath(self::REPOSITORY) . '/packages/*';
        $repository['options']['symlink'] = false;

        return json_encode($repository, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function copyDirectory(string $from, string $to): void
    {
        mkdir($to);
        foreach (scandir($from) as $entry) {
            if (in_array($entry, ['.', '..', 'vendor', 'composer.lock', '.phpunit.cache'], true)) {
                continue;
            }
            is_dir("{$from}/{$entry}") ? self::copyDirectory("{$from}/{$entry}", "{$to}/{$entry}") : copy("{$from}/{$entry}", "{$to}/{$entry}");
        }
    }

    public static function packages(): iterable
    {
        yield 'framework' => ['framework'];
        yield 'FastRoute adapter' => ['router-fastroute'];
        yield 'Sunrise adapter' => ['router-sunrise'];
    }

    #[DataProvider('packages')]
    public function testEachPackageInstallsAloneAndPassesItsOwnTests(string $package): void
    {
        $directory = $this->work . '/' . $package;
        self::copyDirectory(self::REPOSITORY . '/packages/' . $package, $directory);
        if ($package !== 'framework') {
            $this->composer(['config', 'repositories.xpsr', $this->packagesRepository()], $directory);
        }
        $this->composer(['validate', '--strict', '--no-check-lock'], $directory);
        $this->composer(['install', '--prefer-dist'], $directory);
        if ($package !== 'framework') {
            self::assertDirectoryExists($directory . '/vendor/crystoline/xpsr-framework/src');
            self::assertFalse(is_link($directory . '/vendor/crystoline/xpsr-framework'), 'The framework is installed as a copy.');
        }
        $output = $this->runCommand([PHP_BINARY, 'vendor/bin/phpunit', '--colors=never'], $directory);
        self::assertMatchesRegularExpression('/^OK \(\d+ tests?, \d+ assertions?\)$/m', $output);
    }

    public function testCreateProjectBuildsAWorkingApplicationWithEitherAdapter(): void
    {
        $skeleton = $this->work . '/skeleton';
        $this->runCommand([PHP_BINARY, self::REPOSITORY . '/scripts/export-skeleton.php', $skeleton], $this->work);
        self::assertFileDoesNotExist($skeleton . '/composer.lock');
        self::assertDirectoryDoesNotExist($skeleton . '/packages');
        $manifest = json_decode((string) file_get_contents($skeleton . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('repositories', $manifest);
        self::assertArrayNotHasKey('autoload-dev', $manifest);

        $skeletonRepository = json_encode([
            'type' => 'path',
            'url' => $skeleton,
            'options' => ['symlink' => false, 'versions' => ['crystoline/xpsr' => '0.1.x-dev']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $project = $this->work . '/my app';
        $this->composer([
            'create-project', 'crystoline/xpsr', $project, '0.1.x-dev',
            '--repository=' . $skeletonRepository,
            '--repository=' . $this->packagesRepository(),
            '--add-repository',
        ], $this->work);

        self::assertFileExists($project . '/.env', 'The post-install script creates .env.');
        self::assertFalse(is_link($project . '/vendor/crystoline/xpsr-framework'));
        self::assertDirectoryDoesNotExist($project . '/vendor/crystoline/xpsr-router-sunrise', 'Only the default adapter is installed.');
        $xpsr = fn(string ...$arguments): string => $this->runCommand([PHP_BINARY, $project . '/xpsr', ...$arguments], $this->work);

        self::assertSame('fastroute', json_decode($xpsr('about', '--json'), true)['router']);
        self::assertStringContainsString('Bootstrap check passed', $xpsr('setup', '--router=fastroute', '--preset=api', '--no-interaction'));
        self::assertSame(['hello'], array_column(json_decode($xpsr('route:list', '--json'), true), 'name'));

        // A real `composer require` of the Sunrise adapter, run by setup.
        $output = $xpsr('setup', '--router=sunrise', '--preset=web', '--install-dependencies', '--no-interaction', '--composer=' . end($this->composer));
        self::assertStringContainsString('Installed crystoline/xpsr-router-sunrise:^0.1@dev.', $output);
        self::assertStringContainsString('matched by Crystoline\Router\Sunrise\SunriseRouter', $output);
        $manifest = json_decode((string) file_get_contents($project . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('crystoline/xpsr-router-sunrise', $manifest['require']);
        self::assertSame('sunrise', json_decode($xpsr('about', '--json'), true)['router']);
        self::assertSame(['hello', 'home'], array_column(json_decode($xpsr('route:list', '--json'), true), 'name'));

        $response = $this->runCommand(
            [PHP_BINARY, $project . '/public/index.php'],
            $this->work,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello', 'HTTP_HOST' => 'localhost'],
        );
        self::assertSame('["helloooooooo"]', $response);

        self::assertStringContainsString('Created app/Http/Controller/ReportController.php', $xpsr('make:controller', 'ReportController'));
    }
}
