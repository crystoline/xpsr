<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Blade;

use ArrayObject;
use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Crystoline\Xpsr\View\ViewNotFoundException;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use RuntimeException;

/**
 * Blade, without the rest of Laravel: views/emails/welcome.blade.php is the view "emails.welcome".
 *
 *     <h1>Hello, {{ $user->name }}</h1>
 *     <form method="post">@csrf ...</form>
 *     <nav>@if ($xpsr->can('update', $task)) ... @endif</nav>
 *
 * Template functions are methods of $xpsr (see BladeFunctions); @csrf prints csrf_field().
 * Layouts, sections, stacks, @include, and the control structures work; components (<x-...>)
 * need parts of a Laravel application and are not supported.
 */
final class BladeViewHandler implements ViewHandlerInterface, ViewFunctionsInterface
{
    private readonly Factory $factory;
    private readonly BladeCompiler $compiler;
    /** @var ArrayObject<string, array{callable, bool}> */
    private readonly ArrayObject $functions;

    /**
     * Blade compiles templates to PHP files: into $cache, or without one, into a directory under
     * the system temporary directory. A template is compiled again when its file changes.
     */
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(private readonly string $directory, bool $debug = false, string|false $cache = false)
    {
        $cache = $cache === false ? sys_get_temp_dir() . '/xpsr-blade-' . substr(hash('sha256', $directory), 0, 16) : $cache;
        if (!is_dir($cache) && !@mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new RuntimeException("Blade compiles views into {$cache}, which could not be created.");
        }
        $files = new Filesystem();
        $this->compiler = new BladeCompiler($files, $cache);
        $compiler = $this->compiler;
        $engines = new EngineResolver();
        $engines->register('blade', static fn(): CompilerEngine => new CompilerEngine($compiler, $files));
        $engines->register('php', static fn(): PhpEngine => new PhpEngine($files));
        $this->factory = new Factory($engines, new FileViewFinder($files, [$directory]), new Dispatcher(new Container()));
        /** @var ArrayObject<string, array{callable, bool}> $functions */
        $functions = new ArrayObject();
        $this->functions = $functions;
        $this->factory->share('xpsr', new BladeFunctions($functions));
        $this->compiler->directive('csrf', static fn(): string => '<?php echo $xpsr->csrf_field(); ?>');
    }

    /** The Blade compiler, for custom directives and other settings. */
    public function compiler(): BladeCompiler
    {
        return $this->compiler;
    }

    /** The view factory, for shared data and composers. */
    public function factory(): Factory
    {
        return $this->factory;
    }

    public function addFunction(string $name, callable $function, bool $html = false): void
    {
        $this->functions[$name] = [$function, $html];
    }

    public function render(string $view, array $data = []): string
    {
        if (!$this->factory->exists($view)) {
            throw new ViewNotFoundException($view, $this->directory . '/' . str_replace('.', '/', $view) . '.blade.php');
        }

        return $this->factory->make($view, $data)->render();
    }
}
