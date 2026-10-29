<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\View\Plates;

use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Crystoline\Xpsr\View\ViewNotFoundException;
use League\Plates\Engine;

/**
 * Plates: views/emails/welcome.php is the view "emails.welcome", a plain PHP template.
 *
 *     <h1>Hello, <?= $this->e($user->name) ?></h1>
 *     <form method="post"><?= $this->csrf_field() ?> ...</form>
 *
 * Plates prints what a template echoes, so escape values with $this->e(). Template functions
 * are called on $this; html ones, such as csrf_field(), return markup to print as it is.
 */
final class PlatesViewHandler implements ViewHandlerInterface, ViewFunctionsInterface
{
    private readonly Engine $engine;

    /**
     * The signature every official engine has. $debug and $cache are unused: Plates templates are
     * PHP, cached by OPcache.
     */
    // @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter
    public function __construct(private readonly string $directory, bool $debug = false, string|false $cache = false)
    {
        $this->engine = new Engine($directory, 'php');
    }

    /** The Plates engine, for folders, shared data, and extensions. */
    public function engine(): Engine
    {
        return $this->engine;
    }

    public function addFunction(string $name, callable $function, bool $html = false): void
    {
        // Templates escape with $this->e() themselves, so html functions and others register alike.
        $this->engine->registerFunction($name, $function);
    }

    public function render(string $view, array $data = []): string
    {
        $name = str_replace('.', '/', $view);
        if (!$this->engine->exists($name)) {
            throw new ViewNotFoundException($view, "{$this->directory}/{$name}.php");
        }

        return $this->engine->render($name, $data);
    }
}
