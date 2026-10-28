<?php

declare(strict_types=1);

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Psr\Http\Message\ResponseInterface;

if (!function_exists('config')) {
    function config(string $name, mixed $default = null): mixed
    {
        $value = app()->getConfig()->get($name);

        return $value !== null ? $value : $default;
    }
}

if (!function_exists('env')) {
    function env(string $name, mixed $default = null): mixed
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return $value === false ? $default : $value;
    }
}

if (!function_exists('app')) {
    function app(): Application
    {
        return Application::getInstance();
    }
}

if (!function_exists('slug')) {
    function slug(string $text, string $divider = '-'): string
    {
        // Replace anything that is not a letter or digit with the divider.
        $text = (string) preg_replace('~[^\pL\d]+~u', $divider, $text);
        $text = (string) iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = (string) preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, $divider);
        $text = strtolower((string) preg_replace('~-+~', $divider, $text));

        return empty($text) ? 'n-a' : $text;
    }
}

if (!function_exists('url')) {
    function url(string $path): string
    {
        $url = (string) app()->getConfig()->get('app.url');

        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('view')) {
    /** @param array<string, mixed> $data */
    function view(string $view, array $data = []): ResponseInterface
    {
        $output = app()->get(ViewHandlerInterface::class)->render($view, $data);

        return app()->get(ResponseBuilder::class)->html($output);
    }
}

if (!function_exists('root_dir')) {
    function root_dir(string $path): string
    {
        return join_paths(app()->getRootDir(), $path);
    }
}

if (!function_exists('join_paths')) {
    function join_paths(string ...$paths): string
    {
        return (string) preg_replace('~[/\\\\]+~', DIRECTORY_SEPARATOR, implode(DIRECTORY_SEPARATOR, $paths));
    }
}
