<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use Crystoline\Xpsr\View\ViewHandlerInterface;
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
}
