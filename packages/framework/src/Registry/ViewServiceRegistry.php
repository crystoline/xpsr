<?php

declare(strict_types=1);

namespace Crystoline\Registry;

use Crystoline\Configuration;
use Crystoline\ServiceContainer\ContainerException;
use Crystoline\ServiceContainer\ServiceRegistry;
use Crystoline\View\ViewHandler;
use Crystoline\View\ViewHandlerInterface;
use Psr\Container\ContainerInterface;

/**
 * Registers the Twig view handler (deferred: Twig loads only when a view is rendered).
 * Requires twig/twig, which the framework suggests but does not install.
 */
class ViewServiceRegistry extends ServiceRegistry
{
    protected bool $delayed = true;

    public function register(): void
    {
        $this->container->factory(ViewHandlerInterface::class, static function (ContainerInterface $container): ViewHandler {
            $config = $container->get(Configuration::class);

            if (!$config instanceof Configuration) {
                throw new ContainerException('The configuration service must be a Configuration instance.');
            }
            // Twig is optional: API-only applications can leave it out until they render a view.
            if (!class_exists(\Twig\Environment::class)) {
                throw new ContainerException('Rendering views needs Twig. Run: composer require twig/twig');
            }

            return new ViewHandler(
                $config->get('app.view_dir'),
                (bool) $config->get('app.debug', false),
                $config->get('app.view_cache_dir', false),
            );
        }, shared: true);
    }

    public function providedServices(): array
    {
        return [ViewHandlerInterface::class];
    }
}
