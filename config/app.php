<?php

declare(strict_types=1);

use Crystoline\Xpsr\Http\Middleware\BodyParsingMiddleware;
use Crystoline\Xpsr\Http\Middleware\CorsMiddleware;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\FactoryRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use App\Registry\CommandServiceRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;

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
    // Proxies (IP addresses or CIDR ranges, comma-separated) allowed to set Forwarded/X-Forwarded-* headers.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'cipher' => 'AES-256-CBC',
    // PSR-4 namespace and directory of application code; make:* generators write here.
    'namespace' => 'App',
    'source_dir' => 'app',
    // twig, or plates, latte, or blade with crystoline/xpsr-view-<engine> installed.
    'view_engine' => env('VIEW_ENGINE', 'twig'),
    'view_dir' => dirname(__DIR__) . '/views',
    // Compiled templates; a changed template is compiled again. false compiles on every request.
    'view_cache_dir' => dirname(__DIR__) . '/var/cache/views',
    'registry' => [
        FactoryRegistry::class,
        RouteServiceRegistry::class,
        ViewServiceRegistry::class,
        SessionServiceRegistry::class,
        AuthServiceRegistry::class,
        EventServiceRegistry::class,
        ConsoleServiceRegistry::class,
        CommandServiceRegistry::class,
    ],
    // PSR-15 middleware classes or instances, executed in the listed order.
    'middleware' => [
        // Answers cross-origin browser requests; inactive until config/cors.php allows an origin.
        CorsMiddleware::class,
        // Parses JSON bodies, and form bodies on methods other than POST, into getParsedBody().
        BodyParsingMiddleware::class,
    ],
];
