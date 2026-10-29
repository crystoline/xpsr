<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Latte;

use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Crystoline\Xpsr\View\ViewNotFoundException;
use Latte\Engine;
use Latte\Loaders\FileLoader;
use Latte\Runtime\Html;
use RuntimeException;
use Stringable;

/**
 * Latte: views/emails/welcome.latte is the view "emails.welcome".
 *
 *     <h1>Hello, {$user->name}</h1>
 *     <form method="post">{csrf_field()} ...</form>
 *
 * Latte escapes by context (HTML text, attributes, JavaScript, CSS, URLs). Template functions are
 * called by name; html ones, such as csrf_field(), print their markup as it is.
 */
final class LatteViewHandler implements ViewHandlerInterface, ViewFunctionsInterface
{
    private readonly Engine $engine;

    /**
     * Compiled templates go to $cache, or are compiled on each request without one. A template is
     * compiled again when its content changes, with or without $debug, so a deploy never serves
     * old templates.
     */
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(private readonly string $directory, bool $debug = false, string|false $cache = false)
    {
        // Latte creates only the last level of a missing directory, and var/cache may not exist yet.
        if ($cache !== false && !is_dir($cache) && !@mkdir($cache, 0775, true) && !is_dir($cache)) {
            throw new RuntimeException("Latte compiles views into {$cache}, which could not be created.");
        }
        $this->engine = new Engine();
        $this->engine->setLoader(new FileLoader($directory));
        $this->engine->setCacheDirectory($cache === false ? null : $cache);
        $this->engine->setAutoRefresh(true);
    }

    /** The Latte engine, for filters, extensions, and other settings. */
    public function engine(): Engine
    {
        return $this->engine;
    }

    public function addFunction(string $name, callable $function, bool $html = false): void
    {
        $this->engine->addFunction($name, $html ? static function (mixed ...$arguments) use ($function): Html {
            $markup = $function(...$arguments);

            return new Html(is_scalar($markup) || $markup instanceof Stringable ? (string) $markup : '');
        } : $function);
    }

    public function render(string $view, array $data = []): string
    {
        $file = str_replace('.', '/', $view) . '.latte';
        if (!is_file($this->directory . '/' . $file)) {
            throw new ViewNotFoundException($view, $this->directory . '/' . $file);
        }

        return $this->engine->renderToString($file, $data);
    }
}
