<?php

declare(strict_types=1);

namespace Crystoline\ServiceContainer;

use Closure;
use Throwable;

/** @internal A registration group shared by every service it advertises. */
final class DeferredServices
{
    private bool $loading = false;
    private bool $loaded = false;
    private ?ContainerException $failure = null;

    public function __construct(private readonly Closure $loader) {}

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }

    public function load(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->loaded) {
            return;
        }

        if ($this->loading) {
            throw new ContainerException('Circular deferred registration: the requested service has not been defined yet.');
        }

        $this->loading = true;

        try {
            ($this->loader)();
            $this->loaded = true;
        } catch (Throwable $exception) {
            $this->failure = new ContainerException(
                'Deferred service registration failed: ' . $exception->getMessage(),
                0,
                $exception,
            );

            throw $this->failure;
        } finally {
            $this->loading = false;
        }
    }
}
