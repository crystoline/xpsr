<?php

declare(strict_types=1);

namespace Crystoline\Foundation;

use Crystoline\Contracts\Container\ContainerInterface;
use Crystoline\ServiceContainer\ServiceRegistry;
use LogicException;
use RuntimeException;
use Throwable;

final class ServiceProviderManager
{
    /** @var array<class-string<ServiceRegistry>, ServiceRegistry> */
    private array $providers = [];

    /** @var array<class-string<ServiceRegistry>, true> */
    private array $registered = [];

    /** @var array<class-string<ServiceRegistry>, true> */
    private array $booted = [];

    private bool $registrationComplete = false;
    private bool $started = false;
    private bool $complete = false;
    private ?RuntimeException $failure = null;

    public function __construct(private readonly ContainerInterface $container) {}

    /** @param array<array-key, mixed> $classes Provider class names from application configuration. */
    public function boot(array $classes): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->complete) {
            return;
        }

        if ($this->started) {
            throw new LogicException('Recursive application boot is not supported.');
        }

        $this->started = true;

        try {
            foreach ($classes as $class) {
                if (!is_string($class) || !is_subclass_of($class, ServiceRegistry::class)) {
                    throw new RuntimeException('Every app.registry entry must be a service registry class.');
                }

                $this->providers[$class] ??= new $class($this->container);
            }

            foreach ($this->providers as $class => $provider) {
                if ($provider->isDelayed()) {
                    $this->container->defer($provider->providedServices(), function () use ($class): void {
                        $this->registerProvider($class);

                        if ($this->registrationComplete) {
                            $this->bootProvider($class);
                        }
                    });
                }
            }

            foreach ($this->providers as $class => $provider) {
                if (!$provider->isDelayed()) {
                    $this->registerProvider($class);
                }
            }

            $this->registrationComplete = true;

            foreach (array_keys($this->providers) as $class) {
                if (isset($this->registered[$class])) {
                    $this->bootProvider($class);
                }
            }

            $this->complete = true;
        } catch (Throwable $exception) {
            $this->failure = new RuntimeException('Application provider lifecycle failed: ' . $exception->getMessage(), 0, $exception);

            throw $this->failure;
        }
    }

    /** @param class-string<ServiceRegistry> $class */
    private function registerProvider(string $class): void
    {
        if (!isset($this->registered[$class])) {
            $this->providers[$class]->register();
            $this->registered[$class] = true;
        }
    }

    /** @param class-string<ServiceRegistry> $class */
    private function bootProvider(string $class): void
    {
        if (!isset($this->booted[$class])) {
            $this->providers[$class]->boot();
            $this->booted[$class] = true;
        }
    }
}
