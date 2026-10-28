<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Psr\Container\ContainerInterface;

class FactoryRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $configuration = $this->container->get(Configuration::class);

        if (!$configuration instanceof Configuration) {
            throw new ContainerException('The configuration service must be a Configuration instance.');
        }

        $factories = $configuration->get('factory', []);

        if (!is_array($factories)) {
            throw new ContainerException('Factory configuration must be an array.');
        }

        foreach ($factories as $id => $factory) {
            if (is_string($factory) && class_exists($factory)) {
                $this->container->factory($id, static function (ContainerInterface $container) use ($factory): mixed {
                    $instance = $container->get($factory);

                    if (!is_callable($instance)) {
                        throw new ContainerException(sprintf('Factory class "%s" must be invokable.', $factory));
                    }

                    return $instance($container);
                });

                continue;
            }

            if (!is_callable($factory)) {
                throw new ContainerException(sprintf('Factory for "%s" must be a callable or invokable class name.', $id));
            }

            $this->container->factory($id, $factory);
        }
    }
}
