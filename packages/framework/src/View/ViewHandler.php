<?php

namespace Crystoline\Xpsr\View;

class ViewHandler implements ViewHandlerInterface
{
    private \Twig\Environment $engine;

    /**
     * @param string $dir /path/to/templates
     */
    public function __construct(string $dir, bool $debug = false, string|false $cache = false)
    {
        $loader = new \Twig\Loader\FilesystemLoader(['views' => $dir]);

        $this->engine = new \Twig\Environment($loader, [
            'cache' => $cache,
            'debug' => $debug,
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
        $view = str_replace('.', '/', $view);
        $template = $this->engine->load($view . '.twig');
        return $template->render($data);
    }
}
