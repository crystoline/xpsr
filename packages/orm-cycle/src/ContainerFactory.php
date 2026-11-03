<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Spiral\Core\FactoryInterface;

/**
 * Builds the classes Cycle asks for (repositories, mappers, sources, typecast handlers) from
 * XPSR's container: the parameters Cycle passes by name, such as the repository's Select, and
 * the rest, such as a clock or a service of the application, from the container.
 *
 * @internal
 */
final class ContainerFactory implements FactoryInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function make(string $alias, array $parameters = []): mixed
    {
        if ($parameters === [] && $this->container->has($alias)) {
            return $this->container->get($alias);
        }
        if (!class_exists($alias)) {
            throw new LogicException("Cycle asked for {$alias}, which is not a class.");
        }
        $class = new ReflectionClass($alias);
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return $class->newInstance();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $arguments[$name] = $parameters[$name];
                continue;
            }
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
                $arguments[$name] = $this->container->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
                continue;
            }
            if ($type !== null && $type->allowsNull()) {
                $arguments[$name] = null;
                continue;
            }
            throw new LogicException("Cannot build {$alias}: nothing provides its constructor parameter \${$name}.");
        }

        return $class->newInstanceArgs($arguments);
    }
}
