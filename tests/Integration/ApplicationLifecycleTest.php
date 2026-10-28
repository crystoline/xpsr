<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Contracts\Container\ContainerBuilderInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Session\SessionManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Crystoline\Xpsr\Tests\Fixtures\Container\Dependency;
use Crystoline\Xpsr\Tests\Fixtures\Container\InvokableService;

final class ApplicationLifecycleTest extends TestCase
{
    public function testApplicationOwnsAndUsesTheSuppliedContainer(): void
    {
        $container = new ServiceContainer();
        $container->instance('custom.value', 'preserved');
        $application = new Application(dirname(__DIR__, 2), $container);
        $application->boot();
        self::assertFalse((new ReflectionClass($application))->getParentClass());
        self::assertSame($container, $application->getContainer());
        self::assertSame($container, $application->get(ContainerInterface::class));
        self::assertSame($container, $application->get(ContainerBuilderInterface::class));
        self::assertSame($application, $container->get(Application::class));
        self::assertSame('preserved', $application->get('custom.value'));
        self::assertTrue($application->has('custom.value'));
    }

    public function testConfiguredProviderBootsAfterFactoriesAreRegistered(): void
    {
        $container = new ServiceContainer();
        $provider = new class ($container) extends ServiceRegistry {
            public function register(): void
            {
                $this->container->singleton(Dependency::class);
            }

            public function boot(): void
            {
                $this->container->instance('boot.result', $this->container->get('factory.result'));
            }
        };
        $application = new Application(dirname(__DIR__, 2), $container);
        $config = $application->getConfig();
        $config->set('app.registry', [$provider::class, ...$config->get('app.registry')]);
        $factories = $config->get('factory');
        $factories['factory.result'] = static fn(ContainerInterface $container) => $container->get(Dependency::class);
        $factories['class.factory'] = InvokableService::class;
        $config->set('factory', $factories);
        $application->boot();

        self::assertSame($application->get(Dependency::class), $application->get('boot.result'));
        self::assertSame('called', $application->get('class.factory'));
        self::assertInstanceOf(InvokableService::class, $application->get(InvokableService::class));

        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/hello');
        self::assertSame(200, $application->getHttpKernel()->handle($request)->getStatusCode());
    }

    public function testRecursiveApplicationBootFailsWithoutRecursingIndefinitely(): void
    {
        $container = new ServiceContainer();
        $provider = new class ($container) extends ServiceRegistry {
            public function register(): void {}

            public function boot(): void
            {
                $this->container->get(Application::class)->boot();
            }
        };
        $application = new Application(dirname(__DIR__) . '/Fixtures', $container);
        $application->getConfig()->set('app.registry', [$provider::class]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recursive application boot');
        $application->boot();
    }

    public function testTheSessionProviderNeverStartsAPhpSession(): void
    {
        $status = session_status();
        $application = new Application(dirname(__DIR__, 2));
        $application->boot();
        self::assertSame($status, session_status());
        self::assertInstanceOf(SessionManager::class, $application->get(SessionManager::class));
        self::assertSame($status, session_status(), 'XPSR sessions do not use session_start().');
    }
}
