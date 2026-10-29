<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\Tests\Fixtures\EchoViewHandler;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewServiceRegistryTest extends TestCase
{
    public function testRendersTwigTemplatesWithDotSeparatedNames(): void
    {
        $views = TemporaryDirectory::create('views');
        try {
            mkdir($views . '/emails');
            file_put_contents($views . '/emails/welcome.twig', 'Hello {{ name }}, {{ html }}');
            $config = new Configuration();
            $config->set('app.view_dir', $views);
            $container = new ServiceContainer();
            $container->instance(Configuration::class, $config);
            (new ViewServiceRegistry($container))->register();

            $handler = $container->get(ViewHandlerInterface::class);
            self::assertSame('Hello Ada, &lt;b&gt;', $handler->render('emails.welcome', ['name' => 'Ada', 'html' => '<b>']));
            self::assertSame($handler, $container->get(ViewHandlerInterface::class), 'The handler is shared.');
        } finally {
            TemporaryDirectory::remove($views);
        }
    }

    public function testACustomEngineGetsTheTemplateFunctions(): void
    {
        $handler = $this->handler(['app.view_engine' => EchoViewHandler::class]);
        self::assertInstanceOf(EchoViewHandler::class, $handler);
        self::assertSame(['csrf_token' => false, 'csrf_field' => true, 'session' => false, 'auth_user' => false, 'can' => false], $handler->functions);
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidEngines')]
    public function testInvalidEngineSettingsAreExplained(array $config, string $message): void
    {
        try {
            $this->handler($config);
            self::fail('The handler was created.');
        } catch (ContainerException $exception) {
            $reason = $exception->getPrevious() ?? $exception;
            self::assertStringContainsString($message, $reason->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidEngines(): iterable
    {
        yield 'unknown engine' => [['app.view_engine' => 'mustache'], 'app.view_engine must be twig, plates, latte, blade, or a ViewHandlerInterface service class.'];
        yield 'not a handler' => [['app.view_engine' => \stdClass::class], 'app.view_engine must be twig, plates, latte, blade'];
        yield 'no view directory' => [['app.view_dir' => null], 'app.view_dir must be the directory of the view templates.'];
    }

    public function testEachOfficialEngineIsNamedWithItsPackage(): void
    {
        foreach (ViewServiceRegistry::ENGINES as $engine => $official) {
            self::assertSame("crystoline/xpsr-view-{$engine}", $official['package']);
            self::assertStringStartsWith('Crystoline\Xpsr\View\\' . ucfirst($engine) . '\\', $official['class']);
        }
    }

    /** @param array<string, mixed> $settings */
    private function handler(array $settings): ViewHandlerInterface
    {
        $config = new Configuration();
        $config->set('app.view_dir', sys_get_temp_dir());
        foreach ($settings as $key => $value) {
            $config->set($key, $value);
        }
        $container = new ServiceContainer();
        $container->instance(Configuration::class, $config);
        (new ViewServiceRegistry($container))->register();

        return $container->get(ViewHandlerInterface::class);
    }
}
