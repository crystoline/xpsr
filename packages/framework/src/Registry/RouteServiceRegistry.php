<?php

declare(strict_types=1);

namespace Crystoline\Registry;

use Crystoline\Application;
use Crystoline\Configuration;
use Crystoline\Contracts\Routing\RouterInterface;
use Crystoline\Contracts\Routing\UrlGeneratorInterface;
use Crystoline\Routing\RouteCollection;
use Crystoline\Routing\UrlGenerator;
use Crystoline\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class RouteServiceRegistry extends ServiceRegistry
{
    /**
     * Official adapters, each in its own Composer package. Classes are named as strings so the
     * framework does not depend on any adapter package.
     */
    public const ADAPTERS = [
        'fastroute' => ['class' => 'Crystoline\Router\FastRoute\FastRouteRouter', 'package' => 'crystoline/xpsr-router-fastroute'],
        'sunrise' => ['class' => 'Crystoline\Router\Sunrise\SunriseRouter', 'package' => 'crystoline/xpsr-router-sunrise'],
    ];

    public function providedServices(): array
    {
        return [RouterInterface::class, RouteCollection::class, UrlGeneratorInterface::class];
    }

    public function register(): void
    {
        $this->container->factory(RouteCollection::class, static function (ContainerInterface $container): RouteCollection {
            $root = $container->get(Application::class)->getRootDir();
            $files = $container->get(Configuration::class)->get('routing.files', ['routes/api.php', 'routes/web.php']);
            if (!is_array($files)) {
                throw new InvalidArgumentException('routing.files must be an array of paths relative to the application root.');
            }
            $routes = new RouteCollection();
            foreach ($files as $file) {
                if (!is_string($file) || !is_file($root . '/' . $file)) {
                    throw new InvalidArgumentException('A configured route file does not exist.');
                }
                $configure = require $root . '/' . $file;
                if (!is_callable($configure)) {
                    throw new InvalidArgumentException("Route file must return a callable: {$file}");
                }
                $configure($routes);
            }
            $routes->freeze();
            return $routes;
        }, shared: true);

        $this->container->factory(RouterInterface::class, static function (ContainerInterface $container): RouterInterface {
            $adapter = $container->get(Configuration::class)->get('routing.adapter', 'fastroute');
            $official = is_string($adapter) ? (self::ADAPTERS[$adapter] ?? null) : null;
            if ($official !== null && !class_exists($official['class'])) {
                throw new InvalidArgumentException(sprintf(
                    'The %s router adapter is not installed. Run: php xpsr setup --router=%s --install-dependencies (or composer require %s).',
                    $adapter,
                    $adapter,
                    $official['package'],
                ));
            }
            $class = $official['class'] ?? $adapter;
            if (!is_string($class) || !is_a($class, RouterInterface::class, true)) {
                throw new InvalidArgumentException('routing.adapter must be ' . implode(', ', array_keys(self::ADAPTERS)) . ', or a RouterInterface service class.');
            }
            // Custom adapters can use ordinary container factories for extra dependencies.
            return $container->get($class);
        }, shared: true);
        $this->container->factory(UrlGeneratorInterface::class, static fn(ContainerInterface $container) => new UrlGenerator($container->get(RouteCollection::class)), shared: true);
    }
}
