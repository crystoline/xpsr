<?php

namespace Crystoline\Xpsr\View;

/** The Twig engine: views/emails/welcome.twig is the view "emails.welcome". */
class ViewHandler implements ViewHandlerInterface, ViewFunctionsInterface
{
    private \Twig\Environment $engine;

    /**
     * Compiled templates go to $cache, or are compiled on each request without one. A template is
     * compiled again when its file is newer than the compiled one, with or without $debug: the
     * check costs about 0.01 ms per page, and stops a deploy from serving old templates.
     *
     * @param string $dir /path/to/templates
     */
    public function __construct(private readonly string $dir, bool $debug = false, string|false $cache = false)
    {
        $loader = new \Twig\Loader\FilesystemLoader(['views' => $dir]);

        $this->engine = new \Twig\Environment($loader, [
            'cache' => $cache,
            'debug' => $debug,
            'auto_reload' => true,
        ]);

        $this->registerHelpers();
    }
    private function registerHelpers()
    {

        $funcs =  $this->getFunctions(); ///Cache

        foreach ($funcs as $func) {
            if (!function_exists($func)) {
                continue;
            }
            $filter = new \Twig\TwigFilter($func, function ($string) use ($func) {
                return $func($string);
            });
            $this->engine->addFilter($filter);
        }

    }
    protected function getFunctions(): array
    {
        return [
            'config',
            'url',
            '_',
        ];
    }

    /**
     * Make a PHP callable available in templates as a function, for example
     * addFunction('asset', fn(string $path): string => '/build/' . $path). Add functions before the
     * first render. With $html, the result is written without escaping, so it must be safe HTML.
     */
    public function addFunction(string $name, callable $function, bool $html = false): void
    {
        $this->engine->addFunction(new \Twig\TwigFunction($name, $function, $html ? ['is_safe' => ['html']] : []));
    }

    public function render(string $view, array $data = []): string
    {
        $file = str_replace('.', '/', $view) . '.twig';
        try {
            $template = $this->engine->load($file);
        } catch (\Twig\Error\LoaderError $exception) {
            throw new ViewNotFoundException($view, $this->dir . '/' . $file, $exception);
        }
        return $template->render($data);
    }
}
