<?php

declare(strict_types=1);

namespace Crystoline\ServiceContainer;

use Crystoline\Contracts\Container\ContainerInterface;

abstract class ServiceRegistry
{
    protected bool $delayed = false;

    public function __construct(protected readonly ContainerInterface $container) {}

    /** Register definitions; do not start application services here. */
    abstract public function register(): void;

    /** Runs after all eager providers have registered their definitions. */
    public function boot(): void {}

    public function isDelayed(): bool
    {
        return $this->delayed;
    }

    /** @return list<string> */
    public function providedServices(): array
    {
        return [];
    }
}
