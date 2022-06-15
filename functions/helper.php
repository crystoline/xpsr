<?php

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Sunrise\Http\Message\ResponseFactory;

if(!function_exists('config')){

    /**
     * @param string $name
     * @param string $default
     * @return mixed|null
     */
    function config($name, $default = null){
        return app()->getConfig()->get($name)?: $default;
    }
}

if(!function_exists('env')){

    /**
     * @param string $name
     * @param string|null $default
     * @return mixed
     */
    function env(string $name, string $default = null)
    {
        return getenv($name)?:$default;
    }
}
if(!function_exists('database_path')){


    /**
     * @param string $path
     * @return string
     */
    function database_path(string $path)
    {
        return __SITEPATH__ . DIRECTORY_SEPARATOR. 'database'. DIRECTORY_SEPARATOR . $path;
    }
}
if(!function_exists('app')){

    /**
     * @return Application
     */
    function app()
    {
        return Application::getInstance();
    }
}

if(!function_exists('slug')){

    function slug(string $text, string $divider = '-'): string
    {
        // replace non letter or digits by divider
        $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, $divider);

        // remove duplicate divider
        $text = preg_replace('~-+~', $divider, $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }
}

if(!function_exists('url')){

    /**
     * @return Application
     */
    function url(string $path): string
    {
        $url = app()->getConfig()->get('app.url');
        return rtrim($url, '/') . '/'. ltrim($path, '/');
    }
}

if(!function_exists('view')){

    function view(string $view, array $data = array()): ResponseInterface
    {
        /** @var ViewHandlerInterface $viewHandler */
        $viewHandler = app()->build(ViewHandlerInterface::class);
        $output = $viewHandler->render($view, $data);

        return (new ResponseFactory())->createHtmlResponse(200, $output);
    }
}

if(!function_exists('root_dir')){

    function root_dir(string $path): string
    {

        $root = app()->getRootDir();


        return join_paths($root, $path);
    }
}

function join_paths(...$paths) {
    return preg_replace('~[/\\\\]+~', DIRECTORY_SEPARATOR, implode(DIRECTORY_SEPARATOR, $paths));
}




