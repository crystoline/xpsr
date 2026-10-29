<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Blade\Tests;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\View\Blade\BladeViewHandler;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** app.view_engine = blade: template functions on $xpsr, @csrf, layouts, and compiled views. */
final class BladeEngineSelectionTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/xpsr-blade-test-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/views/layouts', 0777, true);
        mkdir($this->base . '/cache');
        file_put_contents($this->base . '/views/layouts/main.blade.php', '<main>@yield(\'content\')</main>');
        file_put_contents($this->base . '/views/home.blade.php', <<<'BLADE'
            @extends('layouts.main')
            @section('content')Hi, {{ $xpsr->auth_user() === null ? 'guest' : 'member' }}.@endsection
            BLADE);
        file_put_contents($this->base . '/views/form.blade.php', '<form>@csrf</form>');
        file_put_contents($this->base . '/views/unknown.blade.php', '{{ $xpsr->nope() }}');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    public function testTheViewProviderBuildsBladeWithTheTemplateFunctions(): void
    {
        $handler = $this->handler();
        self::assertInstanceOf(BladeViewHandler::class, $handler);
        self::assertSame('<main>Hi, guest.</main>', trim($handler->render('home')));
        self::assertNotSame([], glob($this->base . '/cache/*.php'), 'Compiled into app.view_cache_dir.');
    }

    public function testCsrfPrintsTheCsrfField(): void
    {
        $this->assertRenderFails('form', 'csrf_token() and csrf_field() need a session.');
    }

    public function testAnUnknownFunctionListsTheOthers(): void
    {
        $this->assertRenderFails('unknown', 'There is no template function nope(). Functions: csrf_token, csrf_field, session, auth_user, can.');
    }

    private function assertRenderFails(string $view, string $message): void
    {
        try {
            $this->handler()->render($view);
            self::fail('The view rendered.');
        } catch (Throwable $exception) {
            // Blade wraps a template's exception in a ViewException naming the template.
            self::assertStringContainsString($message, ($exception->getPrevious() ?? $exception)->getMessage());
        }
    }

    private function handler(): ViewHandlerInterface
    {
        $config = new Configuration();
        $config->set('app.view_engine', 'blade');
        $config->set('app.view_dir', $this->base . '/views');
        $config->set('app.view_cache_dir', $this->base . '/cache');
        $container = new ServiceContainer();
        $container->instance(Configuration::class, $config);
        (new ViewServiceRegistry($container))->register();

        return $container->get(ViewHandlerInterface::class);
    }
}
