<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Plates\Tests;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\View\Plates\PlatesViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use PHPUnit\Framework\TestCase;

/** app.view_engine = plates, with the view provider's template functions. */
final class PlatesEngineSelectionTest extends TestCase
{
    public function testTheViewProviderBuildsPlatesWithTheTemplateFunctions(): void
    {
        $views = sys_get_temp_dir() . '/xpsr-plates-' . bin2hex(random_bytes(6));
        mkdir($views . '/pages', 0777, true);
        try {
            file_put_contents($views . '/pages/home.php', 'Hi, <?= $this->auth_user() === null ? \'guest\' : \'member\' ?>.');
            $config = new Configuration();
            $config->set('app.view_engine', 'plates');
            $config->set('app.view_dir', $views);
            $container = new ServiceContainer();
            $container->instance(Configuration::class, $config);
            (new ViewServiceRegistry($container))->register();

            $handler = $container->get(ViewHandlerInterface::class);
            self::assertInstanceOf(PlatesViewHandler::class, $handler);
            self::assertSame('Hi, guest.', $handler->render('pages.home'));
            self::assertTrue($handler->engine()->doesFunctionExist('csrf_field'));
        } finally {
            unlink($views . '/pages/home.php');
            rmdir($views . '/pages');
            rmdir($views);
        }
    }
}
