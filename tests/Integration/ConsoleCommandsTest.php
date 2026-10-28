<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\Commands\RouteListCommand;
use Crystoline\Xpsr\Console\Commands\ServeCommand;
use Crystoline\Xpsr\Routing\RouteCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fixtures\Console\BufferedConsoleOutput;

final class ConsoleCommandsTest extends TestCase
{
    public static function adapters(): iterable
    {
        yield 'fastroute' => ['fastroute'];
        yield 'sunrise' => ['sunrise'];
    }

    #[DataProvider('adapters')]
    public function testRouteListUsesUnchangedDefinitionsForEitherAdapter(string $adapter): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getConfig()->set('routing.adapter', $adapter);
        $output = new BufferedConsoleOutput();
        $status = $app->getConsoleKernel()->run(new ArrayInput(['command' => 'route:list', '--json' => true]), $output);
        self::assertSame(0, $status, $output->errors());
        $routes = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['hello', 'home'], array_column($routes, 'name'));
        self::assertSame('Closure', $routes[0]['handler']);
        self::assertSame(['GET'], $routes[1]['methods']);
        self::assertSame(['Crystoline\Xpsr\Session\SessionMiddleware', 'Crystoline\Xpsr\Session\CsrfMiddleware'], $routes[1]['middleware']);
    }

    public function testSkeletonProvidesTheEventDispatcherAndEventList(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $output = new BufferedConsoleOutput();
        $status = $app->getConsoleKernel()->run(new ArrayInput(['command' => 'event:list', '--json' => true]), $output);
        self::assertSame(0, $status, $output->errors());
        self::assertSame([], json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR), 'The skeleton ships with no listeners.');
        $event = new \stdClass();
        self::assertSame($event, $app->get(\Psr\EventDispatcher\EventDispatcherInterface::class)->dispatch($event));
    }

    public function testRouteFiltersAndMetadataDoNotInstantiateHandlers(): void
    {
        $routes = new RouteCollection();
        $routes->group('/admin', static function (RouteCollection $routes): void {
            $routes->add(['GET', 'POST'], '/users/{id}', ['not.instantiated', 'show'])->name('users');
        }, ['middleware.not.instantiated']);
        $routes->get('/other', 'another.handler');
        $tester = new CommandTester(new RouteListCommand($routes));
        self::assertSame(0, $tester->execute(['--method' => 'post', '--name' => 'users', '--path' => '/admin', '--json' => true]));
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $data);
        self::assertSame('not.instantiated::show', $data[0]['handler']);
        self::assertSame(['middleware.not.instantiated'], $data[0]['middleware']);
        self::assertSame(0, $tester->execute(['--method' => 'DELETE', '--json' => true]));
        self::assertSame([], json_decode($tester->getDisplay(), true));
        self::assertSame(2, $tester->execute(['--method' => 'G ET'], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('only letters', $tester->getErrorOutput());
    }

    public function testRouteTableShowsUnnamedRoutesAndImplicitMethodNotice(): void
    {
        $routes = new RouteCollection();
        $routes->get('/unnamed', 'handler');
        $tester = new CommandTester(new RouteListCommand($routes));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('/unnamed', $tester->getDisplay());
        self::assertStringContainsString('HEAD fallback', $tester->getDisplay());
    }

    public static function invalidServerOptions(): iterable
    {
        yield 'shell host' => [['--host' => 'localhost; touch /tmp/no']];
        yield 'URL host' => [['--host' => 'http://localhost']];
        yield 'empty host' => [['--host' => '']];
        yield 'zero port' => [['--port' => '0']];
        yield 'large port' => [['--port' => '65536']];
        yield 'negative port' => [['--port' => '-1']];
        yield 'shell port' => [['--port' => '3001; echo no']];
        yield 'decimal port' => [['--port' => '3001.5']];
    }

    #[DataProvider('invalidServerOptions')]
    public function testServeRejectsInvalidOptionsBeforeStartingProcess(array $options): void
    {
        $tester = new CommandTester(new ServeCommand(new Application(dirname(__DIR__, 2))));
        self::assertSame(2, $tester->execute($options, ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertNotSame('', $tester->getErrorOutput());
    }

    public function testServeRequiresPublicFrontController(): void
    {
        $tester = new CommandTester(new ServeCommand(new Application(dirname(__DIR__) . '/Fixtures')));
        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('public/index.php', $tester->getErrorOutput());
    }
}
