<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Latte\Tests;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\View\Latte\LatteViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use PHPUnit\Framework\TestCase;

/** app.view_engine = latte, with the view provider's template functions and a cache directory. */
final class LatteEngineSelectionTest extends TestCase
{
    public function testTheViewProviderBuildsLatteWithTheTemplateFunctions(): void
    {
        $base = sys_get_temp_dir() . '/xpsr-latte-' . bin2hex(random_bytes(6));
        mkdir($base . '/views/pages', 0777, true);
        mkdir($base . '/cache');
        try {
            file_put_contents($base . '/views/pages/home.latte', 'Hi, {auth_user() === null ? guest : member}. <a title="{$title}">x</a>');
            $config = new Configuration();
            $config->set('app.view_engine', 'latte');
            $config->set('app.view_dir', $base . '/views');
            $config->set('app.view_cache_dir', $base . '/cache');
            $container = new ServiceContainer();
            $container->instance(Configuration::class, $config);
            (new ViewServiceRegistry($container))->register();

            $handler = $container->get(ViewHandlerInterface::class);
            self::assertInstanceOf(LatteViewHandler::class, $handler);
            self::assertSame('Hi, guest. <a title="&quot;quoted&quot;">x</a>', $handler->render('pages.home', ['title' => '"quoted"']), 'Escaped for an attribute.');
            self::assertNotSame([], glob($base . '/cache/*'), 'Compiled into app.view_cache_dir.');
        } finally {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }
}
