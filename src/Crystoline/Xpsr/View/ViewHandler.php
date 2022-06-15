<?php

namespace Crystoline\Xpsr\View;


class ViewHandler implements ViewHandlerInterface
{
    private \Twig\Environment $engine;

    /**
     * @param string $dir /path/to/templates
     */
    public function __construct(string $dir)
    {
        $loader = new \Twig\Loader\FilesystemLoader(['views' => $dir]);

        $this->engine = new \Twig\Environment($loader, [
            'cache' => false,
            'debug' => true,
        ]);

        $this->registerHelpers();
    }
    private function registerHelpers(){

        $funcs =  $this->getFunctions(); ///Cache

        foreach ($funcs as $func){
            if(!function_exists($func)){
                continue;
            }
            $filter = new \Twig\TwigFilter($func, function ($string) use($func) {
                return $func($string);
            });
            $this->engine->addFilter($filter);
        }

    }
    protected function getFunctions():array {
        return [
            'config',
            'url',
            '_'
        ];
    }

    public function render(string $view, array $data = []): string
    {
        $view = str_replace('.','/',$view);
        $template = $this->engine->load($view.'.twig');
        return $template->render($data);
    }
}