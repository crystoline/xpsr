<?php

declare(strict_types=1);

/*
 * Used by `php xpsr setup`: php bootstrap-check.php <application root>
 *
 * Boots the application, resolves the configured router, and matches each route without
 * placeholders. Prints a JSON summary on success; prints the reason to stderr and exits 1
 * on failure. Route handlers and middleware are never executed.
 */

use Crystoline\Xpsr\Contracts\Routing\RouterInterface;
use Crystoline\Xpsr\Routing\RouteCollection;
use Psr\Http\Message\ServerRequestFactoryInterface;

ini_set('display_errors', 'stderr');

try {
    $root = $argv[1] ?? '';
    if ($root === '' || !is_file($root . '/bootstrap/app.php')) {
        throw new RuntimeException('bootstrap/app.php was not found in the application root.');
    }
    $application = require $root . '/bootstrap/app.php';
    $config = $application->getConfig();
    $adapter = $config->get('routing.adapter', 'fastroute');
    $router = $application->get(RouterInterface::class);
    $routes = $application->get(RouteCollection::class);
    $requests = $application->get(ServerRequestFactoryInterface::class);

    $count = 0;
    $matched = 0;
    foreach ($routes->all() as $route) {
        ++$count;
        if (str_contains($route->path, '{')) {
            continue;
        }
        $method = $route->methods[0];
        $match = $router->match($requests->createServerRequest($method, 'http://localhost' . $route->path));
        if ($match->route?->path !== $route->path || !in_array($method, $match->route->methods, true)) {
            throw new RuntimeException(sprintf('The %s router did not match %s %s.', is_string($adapter) ? $adapter : 'configured', $method, $route->path));
        }
        ++$matched;
    }

    echo json_encode([
        'adapter' => $adapter,
        'router' => $router::class,
        'files' => $config->get('routing.files', []),
        'routes' => $count,
        'matched' => $matched,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
