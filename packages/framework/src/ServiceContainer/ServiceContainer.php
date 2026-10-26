<?php

declare(strict_types=1);

namespace Crystoline\ServiceContainer;

use Closure;
use Crystoline\Contracts\Container\ContainerBuilderInterface;
use Crystoline\Contracts\Container\ContainerInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

class ServiceContainer implements ContainerInterface
{
    /** @var array<string, array{kind: string, value: mixed, shared: bool}> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, DeferredServices> */
    private array $deferred = [];

    /** @var list<string> */
    private array $resolving = [];

    public function __construct()
    {
        foreach ([self::class, PsrContainerInterface::class, ContainerInterface::class, ContainerBuilderInterface::class] as $id) {
            $this->instance($id, $this);
        }
    }

    public function has(string $id): bool
    {
        return $this->isDefined($id)
            || isset($this->deferred[$id])
            || ($id !== '' && class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new NotFoundException(sprintf('Service "%s" was not found.', $id));
        }

        $this->loadDeferred($id);

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolving, true)) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', [...$this->resolving, $id]));
        }

        $this->resolving[] = $id;

        try {
            $definition = $this->definitions[$id] ?? ['kind' => 'class', 'value' => $id, 'shared' => false];
            $value = match ($definition['kind']) {
                'class' => $this->autowire($definition['value']),
                'factory' => ($definition['value'])($this),
                'alias' => $this->get($definition['value']),
                'value' => $definition['value'],
                default => throw new ContainerException('Unknown service definition kind.'),
            };

            if ($definition['shared']) {
                $this->instances[$id] = $value;
            }

            return $value;
        } catch (NotFoundExceptionInterface $exception) {
            // This identifier exists; a missing dependency must not mislabel it as unknown.
            throw new ContainerException(
                sprintf('Unable to resolve service "%s": %s', $id, $exception->getMessage()),
                0,
                $exception,
            );
        } catch (ContainerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContainerException(
                sprintf('Unable to resolve service "%s": %s', $id, $exception->getMessage()),
                0,
                $exception,
            );
        } finally {
            array_pop($this->resolving);
        }
    }

    public function bind(string $id, ?string $class = null): void
    {
        $this->define($id, 'class', $class ?? $id);
    }

    public function singleton(string $id, ?string $class = null): void
    {
        $this->define($id, 'class', $class ?? $id, true);
    }

    public function factory(string $id, callable $factory, bool $shared = false): void
    {
        $this->define($id, 'factory', Closure::fromCallable($factory), $shared);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->define($id, 'value', $value);
    }

    public function alias(string $alias, string $target): void
    {
        $this->assertIdentifier($target);
        $this->define($alias, 'alias', $target);
    }

    public function defer(array $ids, Closure $loader): void
    {
        if ($ids === []) {
            throw new ContainerException('Deferred services must have a non-empty list of unique identifiers.');
        }

        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new ContainerException('Deferred service identifiers must be strings.');
            }

            $this->assertIdentifier($id);

            if ($this->isDefined($id) || isset($this->deferred[$id])) {
                throw new ContainerException(sprintf('Deferred service "%s" already has a definition.', $id));
            }
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new ContainerException('Deferred services must have a non-empty list of unique identifiers.');
        }

        $group = new DeferredServices(function () use ($ids, $loader): void {
            $loader();

            foreach ($ids as $id) {
                if (!$this->isDefined($id)) {
                    throw new ContainerException(sprintf('Deferred provider did not define advertised service "%s".', $id));
                }
            }
        });

        foreach ($ids as $id) {
            $this->deferred[$id] = $group;
        }
    }

    private function define(string $id, string $kind, mixed $value, bool $shared = false): void
    {
        $this->assertIdentifier($id);

        if (in_array($id, $this->resolving, true)) {
            throw new ContainerException(sprintf('Cannot rebind service "%s" while it is being resolved.', $id));
        }

        $group = $this->deferred[$id] ?? null;

        if ($group !== null && !$group->isLoading()) {
            if (!$group->isLoaded() && !$group->hasFailed()) {
                throw new ContainerException(sprintf('Load deferred service "%s" before rebinding it, or replace its provider.', $id));
            }

            unset($this->deferred[$id]);
        }

        unset($this->instances[$id]);
        $this->definitions[$id] = ['kind' => $kind, 'value' => $value, 'shared' => $shared];
    }

    private function loadDeferred(string $id): void
    {
        $group = $this->deferred[$id] ?? null;

        // A provider's boot hook may consume services it has just registered.
        if ($group !== null && !($group->isLoading() && $this->isDefined($id))) {
            $group->load();
        }
    }

    private function isDefined(string $id): bool
    {
        return array_key_exists($id, $this->definitions);
    }

    private function assertIdentifier(string $id): void
    {
        if ($id === '') {
            throw new ContainerException('Service identifiers cannot be empty.');
        }
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new ContainerException(sprintf('Cannot construct "%s": class does not exist.', $class));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Cannot construct "%s": class is not instantiable.', $class));
        }

        $arguments = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isVariadic()) {
                $arguments[] = $this->resolveParameter($parameter);
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = match ($type->getName()) {
                'self' => $parameter->getDeclaringClass()?->getName(),
                'parent' => $parameter->getDeclaringClass()?->getParentClass()?->getName(),
                default => $type->getName(),
            };

            if ($name !== null && $this->has($name)) {
                return $this->get($name);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull() && $type->getName() !== 'mixed') {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot autowire %s::$%s (%s). Register an explicit factory for this class.',
            $parameter->getDeclaringClass()?->getName() ?? 'unknown',
            $parameter->getName(),
            $type === null ? 'untyped parameter' : (string) $type,
        ));
    }
}
