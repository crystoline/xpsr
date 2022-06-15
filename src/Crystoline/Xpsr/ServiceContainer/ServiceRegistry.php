<?php

namespace Crystoline\Xpsr\ServiceContainer;

use Crystoline\Xpsr\Application;
use Psr\Container\ContainerInterface;

abstract class ServiceRegistry
{
    protected bool $delayed = false;
    protected ServiceContainer $container;

    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
    }

    abstract function register(): void;

    public function isDelayed(): bool
    {
        return $this->delayed;
    }

    /**
     * @return string[]
     */
    public function providedServices(): array
    {
        return [];
    }


}