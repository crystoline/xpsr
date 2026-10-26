<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Application;
use Crystoline\Configuration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory as ServerRequestFactory;

final class ApplicationTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testBootstrapAndRoutesWorkFromAnotherWorkingDirectory(): void
    {
        $previousDirectory = getcwd();
        try {
            chdir(sys_get_temp_dir());
            $application = require $this->root() . '/bootstrap/app.php';
            $kernel = $application->getHttpKernel();
            self::assertInstanceOf(RequestHandlerInterface::class, $kernel);
            self::assertSame($application->getContainer(), $application->get(ContainerInterface::class));
            self::assertSame($application, $application->get(Application::class));
            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/hello');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['helloooooooo'], json_decode((string) $response->getBody(), true));
            $home = $kernel->handle($request->withUri($request->getUri()->withPath('/')));
            self::assertSame(200, $home->getStatusCode());
            self::assertStringContainsString('Welcome to XPSR framework.', (string) $home->getBody());
            $again = $kernel->handle($request);
            self::assertSame(200, $again->getStatusCode());
            self::assertSame(['helloooooooo'], json_decode((string) $again->getBody(), true));
        } finally {
            chdir($previousDirectory);
        }
    }

    public function testBootIsIdempotentAndDoesNotConstructAnHttpRequest(): void
    {
        $application = new Application($this->root());
        $calls = 0;
        $sessionStatus = session_status();
        $factories = $application->getConfig()->get('factory');
        $factories[ServerRequestInterface::class] = static function () use (&$calls): ServerRequestInterface {
            ++$calls;
            return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        };
        $application->getConfig()->set('factory', $factories);
        $this->expectOutputString('');
        $application->boot();
        $configuration = $application->get(Configuration::class);
        $application->boot();
        self::assertSame(0, $calls);
        self::assertSame($sessionStatus, session_status());
        self::assertSame($configuration, $application->get(Configuration::class));
        self::assertInstanceOf(ServerRequestInterface::class, $application->get(ServerRequestInterface::class));
        self::assertSame(1, $calls);
    }

    public function testEnvironmentSpecificConfigurationOverridesBaseSettings(): void
    {
        $application = new Application(dirname(__DIR__) . '/Fixtures');
        self::assertSame('Test environment', $application->getConfig()->get('app.name'));
        self::assertFalse($application->getConfig()->get('app.debug'));
        self::assertSame([], $application->getConfig()->get('app.registry'));
    }

    public function testRouterReturnsNotFoundAndMethodNotAllowed(): void
    {
        $kernel = (new Application($this->root()))->getHttpKernel();
        $requests = new ServerRequestFactory();
        self::assertSame(404, $kernel->handle($requests->createServerRequest('GET', 'http://localhost/missing'))->getStatusCode());
        $response = $kernel->handle($requests->createServerRequest('POST', 'http://localhost/hello'));
        self::assertSame(405, $response->getStatusCode());
        self::assertContains('GET', array_map('trim', explode(',', $response->getHeaderLine('Allow'))));
    }
}
