<?php

declare(strict_types=1);

use Crystoline\Http\Middleware\BodyParsingMiddleware;
use Crystoline\Registry\FactoryRegistry;
use Crystoline\Registry\ConsoleServiceRegistry;
use App\Registry\CommandServiceRegistry;
use Crystoline\Registry\RouteServiceRegistry;
use Crystoline\Registry\ViewServiceRegistry;

return [
    'name' => env('APP_NAME', 'XPSR'),
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', 'http://localhost:3001'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    // PSR-4 namespace and directory of application code; make:* generators write here.
    'namespace' => 'App',
    'source_dir' => 'app',
    'view_dir' => dirname(__DIR__) . '/views',
    'view_cache_dir' => false,
    'registry' => [
        FactoryRegistry::class,
        RouteServiceRegistry::class,
        ViewServiceRegistry::class,
        ConsoleServiceRegistry::class,
        CommandServiceRegistry::class,
    ],
    // PSR-15 middleware classes or instances, executed in the listed order.
    'middleware' => [
        // Parses JSON bodies, and form bodies on methods other than POST, into getParsedBody().
        BodyParsingMiddleware::class,
    ],
];
