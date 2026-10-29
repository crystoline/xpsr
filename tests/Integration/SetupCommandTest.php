<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\Commands\SetupCommand;
use Crystoline\Xpsr\Console\Setup\BootstrapCheck;
use Crystoline\Xpsr\Console\Setup\PackageInspectorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tests\Fixtures\SkeletonProject;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;

final class SetupCommandTest extends TestCase
{
    private string $root;
    private string $tools;

    protected function setUp(): void
    {
        $this->root = SkeletonProject::create();
        $this->tools = TemporaryDirectory::create('setup tools');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
        TemporaryDirectory::remove($this->tools);
    }

    /** A Composer stand-in that records its arguments and exits with $status. */
    private function fakeComposer(int $status = 0): string
    {
        $path = $this->tools . '/composer.php';
        file_put_contents($path, '<?php file_put_contents(' . var_export($this->tools . '/composer.log', true)
            . ', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND); exit(' . $status . ');');

        return $path;
    }

    /** @return list<list<string>> */
    private function composerCalls(): array
    {
        $log = $this->tools . '/composer.log';

        return is_file($log) ? array_map(static fn(string $line): array => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES)) : [];
    }

    /**
     * Run a console command in the skeleton project from another working directory.
     *
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{int, string, string} status, stdout, stderr with whitespace collapsed
     */
    private function xpsr(array $arguments, array $environment = []): array
    {
        $process = new Process(
            [PHP_BINARY, $this->root . '/xpsr', ...$arguments],
            $this->tools,
            $environment + BootstrapCheck::environment(),
            timeout: 60,
        );
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), trim((string) preg_replace('/\s+/', ' ', $process->getErrorOutput()))];
    }

    /** @param list<string> $options */
    private function runSetup(array $options): array
    {
        return $this->xpsr(['setup', '--no-interaction', '--composer=' . $this->fakeComposer(), ...$options]);
    }

    private function file(string $relative): ?string
    {
        $path = $this->root . '/' . $relative;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** @return array<string, ?string> */
    private function snapshot(): array
    {
        return ['.env' => $this->file('.env'), 'config/routing.php' => $this->file('config/routing.php')];
    }

    private function httpGet(string $path): string
    {
        $process = new Process(
            [PHP_BINARY, $this->root . '/public/index.php'],
            $this->root,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'HTTP_HOST' => 'localhost'] + BootstrapCheck::environment(),
            timeout: 30,
        );
        $process->run();

        return $process->getOutput();
    }

    public static function presets(): iterable
    {
        yield 'web' => ['web', ['hello', 'home']];
        yield 'api' => ['api', ['hello']];
    }

    /** @param list<string> $names */
    #[DataProvider('presets')]
    public function testFreshProjectsBootWithEitherAdapterAndShowTheSameRoutes(string $preset, array $names): void
    {
        $routeLists = [];
        foreach (['sunrise', 'fastroute'] as $router) {
            [$status, $stdout, $stderr] = $this->runSetup(["--router={$router}", "--preset={$preset}", '--install-dependencies']);
            self::assertSame(0, $status, $stderr);
            self::assertStringContainsString("Router: {$router}. Preset: {$preset}.", $stdout);
            self::assertStringContainsString('Bootstrap check passed: ' . count($names) . ' route(s)', $stdout);
            self::assertMatchesRegularExpression('/^ROUTER_ADAPTER=' . $router . '$/m', (string) $this->file('.env'));

            [$status, $about] = $this->xpsr(['about', '--json']);
            self::assertSame(0, $status);
            self::assertSame($router, json_decode($about, true)['router']);
            [$status, $routes] = $this->xpsr(['route:list', '--json']);
            self::assertSame(0, $status);
            $routeLists[$router] = json_decode($routes, true);
            self::assertSame($names, array_column($routeLists[$router], 'name'));
            self::assertSame('["helloooooooo"]', $this->httpGet('/hello'));
        }
        self::assertSame($routeLists['sunrise'], $routeLists['fastroute']);

        // Sunrise is only a development dependency here, so setup asked Composer for it. FastRoute is required already.
        self::assertSame([['require', 'crystoline/xpsr-router-sunrise:^0.4@dev', '--update-with-dependencies', '--no-interaction', '--working-dir=' . $this->root]], $this->composerCalls());
    }

    public function testRerunningWithoutOptionsKeepsChoicesAndChangesNothing(): void
    {
        self::assertSame(0, $this->runSetup(['--router=fastroute', '--preset=api'])[0]);
        $before = $this->snapshot();
        clearstatcache();
        $modified = filemtime($this->root . '/.env');

        [$status, $stdout] = $this->runSetup([]);
        self::assertSame(0, $status);
        self::assertStringContainsString('Router: fastroute. Preset: api.', $stdout);
        self::assertStringContainsString('Already configured; nothing changed.', $stdout);
        self::assertSame($before, $this->snapshot());
        clearstatcache();
        self::assertSame($modified, filemtime($this->root . '/.env'));
        self::assertSame([], $this->composerCalls());
    }

    public function testExistingEnvironmentFileKeepsEverythingButTheRouterLine(): void
    {
        file_put_contents($this->root . '/.env', "# Local settings\nAPP_NAME=Mine\nROUTER_ADAPTER=sunrise\nAPP_DEBUG=true\n");
        [$status, $stdout, $stderr] = $this->runSetup(['--router=fastroute']);
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Set ROUTER_ADAPTER=fastroute in .env.', $stdout);
        self::assertSame("# Local settings\nAPP_NAME=Mine\nROUTER_ADAPTER=fastroute\nAPP_DEBUG=true\n", $this->file('.env'));
    }

    public function testCreatesEnvironmentFileFromExample(): void
    {
        [$status, $stdout] = $this->runSetup(['--router=fastroute', '--preset=web']);
        self::assertSame(0, $status);
        self::assertStringContainsString('Create .env from .env.example with ROUTER_ADAPTER=fastroute.', $stdout);
        self::assertSame($this->file('.env.example'), $this->file('.env'));
    }

    public function testMissingAdapterPackageRequiresPermissionAndComposerFailureChangesNothing(): void
    {
        $before = $this->snapshot();
        [$status, $stdout, $stderr] = $this->runSetup(['--router=sunrise', '--preset=api']);
        self::assertSame(1, $status);
        self::assertStringContainsString('composer require crystoline/xpsr-router-sunrise:^0.4@dev', $stderr);
        self::assertStringContainsString('--install-dependencies', $stderr);
        self::assertStringContainsString('No files were changed.', $stderr);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->composerCalls());

        $failingComposer = $this->fakeComposer(3);
        [$status, , $stderr] = $this->xpsr(['setup', '-n', '--router=sunrise', '--preset=api', '--install-dependencies', '--composer=' . $failingComposer]);
        self::assertSame(1, $status);
        self::assertStringContainsString('Composer could not install crystoline/xpsr-router-sunrise:^0.4@dev (exit status 3). No files were changed.', $stderr);
        self::assertSame($before, $this->snapshot());
        self::assertCount(1, $this->composerCalls());

        [$status, , $stderr] = $this->xpsr(['setup', '-n', '--router=sunrise', '--install-dependencies', '--composer=' . $this->tools . '/missing-composer']);
        self::assertSame(1, $status);
        self::assertStringContainsString('Composer was not found', $stderr);
        self::assertSame($before, $this->snapshot());
    }

    public function testCustomizedRoutingConfigurationIsNotRewritten(): void
    {
        $custom = "<?php\n\nreturn [\n    'adapter' => env('ROUTER_ADAPTER', 'fastroute'),\n    'files' => ['routes/api.php', 'routes/web.php', 'routes/admin.php'],\n];\n";
        file_put_contents($this->root . '/config/routing.php', $custom);
        file_put_contents($this->root . '/routes/admin.php', "<?php\nreturn static function (\\Crystoline\\Xpsr\\Routing\\RouteCollection \$routes): void {\n    \$routes->get('/admin', static fn() => null)->name('admin');\n};\n");

        [$status, , $stderr] = $this->runSetup(['--router=fastroute', '--preset=api']);
        self::assertSame(1, $status);
        self::assertStringContainsString("config/routing.php has been customized", $stderr);
        self::assertStringContainsString("'files' => ['routes/api.php']", $stderr);
        self::assertSame($custom, $this->file('config/routing.php'));
        self::assertNull($this->file('.env'), 'Nothing is written when any planned change is refused.');

        // Without --preset, custom route files are kept and the router can still be chosen.
        [$status, $stdout, $stderr] = $this->runSetup(['--router=fastroute']);
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Preset: custom route files, unchanged.', $stdout);
        self::assertStringContainsString('Bootstrap check passed: 3 route(s)', $stdout);
        self::assertSame($custom, $this->file('config/routing.php'));
    }

    public function testFailedBootstrapCheckRestoresEveryWrittenFile(): void
    {
        $before = $this->snapshot();
        unlink($this->root . '/routes/api.php');
        [$status, $stdout, $stderr] = $this->runSetup(['--router=fastroute', '--preset=api']);
        self::assertSame(1, $status);
        self::assertStringContainsString('Setup check failed:', $stderr);
        self::assertStringContainsString('A configured route file does not exist.', $stderr);
        self::assertStringContainsString('The previous files were restored.', $stderr);
        self::assertStringNotContainsString('Bootstrap check passed', $stdout);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], array_values(array_filter(scandir($this->root), static fn(string $f): bool => str_ends_with($f, '.tmp'))));
    }

    public function testShellEnvironmentOverrideIsReportedAndRolledBack(): void
    {
        $before = $this->snapshot();
        [$status, , $stderr] = $this->xpsr(['setup', '-n', '--router=fastroute', '--preset=api'], ['ROUTER_ADAPTER' => 'sunrise']);
        self::assertSame(1, $status);
        self::assertStringContainsString('The application still uses the "sunrise" router.', $stderr);
        self::assertSame($before, $this->snapshot());
    }

    public function testCustomAdapterIsKeptWhenNoRouterIsChosen(): void
    {
        $env = "ROUTER_ADAPTER=Crystoline\\Xpsr\\Router\\FastRoute\\FastRouteRouter\n";
        file_put_contents($this->root . '/.env', $env);
        [$status, $stdout, $stderr] = $this->runSetup([]);
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Router: custom (Crystoline\Xpsr\Router\FastRoute\FastRouteRouter), unchanged.', $stdout);
        self::assertSame($env, $this->file('.env'));
        self::assertSame([], $this->composerCalls());
    }

    public function testInvalidChoicesAndDryRunChangeNothing(): void
    {
        $before = $this->snapshot();
        [$status, , $stderr] = $this->runSetup(['--router=symfony']);
        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown router "symfony". Choose fastroute or sunrise.', $stderr);
        [$status, , $stderr] = $this->runSetup(['--preset=mobile']);
        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown preset "mobile"', $stderr);

        [$status, $stdout] = $this->runSetup(['--router=sunrise', '--preset=api', '--dry-run']);
        self::assertSame(0, $status);
        self::assertStringContainsString('Would run: composer require crystoline/xpsr-router-sunrise:^0.4@dev', $stdout);
        self::assertStringContainsString('Would create .env from .env.example with ROUTER_ADAPTER=sunrise.', $stdout);
        self::assertStringContainsString('Would write config/routing.php to load routes/api.php.', $stdout);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->composerCalls());
    }

    public function testInteractiveChoicesAndInstallConfirmation(): void
    {
        $packages = new class implements PackageInspectorInterface {
            public function isRequired(string $package): bool
            {
                return $package !== 'crystoline/xpsr-router-sunrise';
            }

            public function releaseConstraint(): string
            {
                return '^0.4@dev';
            }
        };
        $command = new SetupCommand(new Application($this->root), $packages, new BootstrapCheck());
        $tester = new CommandTester($command);
        $tester->setInputs(['sunrise', 'api', 'no']);
        self::assertSame(1, $tester->execute(['--composer' => $this->fakeComposer()], ['interactive' => true, 'capture_stderr_separately' => true]));
        self::assertStringContainsString('Router adapter', $tester->getDisplay());
        self::assertStringContainsString('Install crystoline/xpsr-router-sunrise:^0.4@dev with Composer now?', $tester->getDisplay());
        self::assertNull($this->file('.env'));

        $tester->setInputs(['sunrise', 'api', 'yes']);
        self::assertSame(0, $tester->execute(['--composer' => $this->fakeComposer()], ['interactive' => true, 'capture_stderr_separately' => true]), $tester->getErrorOutput());
        self::assertCount(1, $this->composerCalls());
        self::assertMatchesRegularExpression('/^ROUTER_ADAPTER=sunrise$/m', (string) $this->file('.env'));
        self::assertStringContainsString("'files' => ['routes/api.php']", (string) $this->file('config/routing.php'));
    }
}
