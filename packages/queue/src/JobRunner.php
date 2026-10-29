<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Runs a job's handle() method, passing each parameter from the container by its class or
 * interface type; parameters with a default value and no service get the default.
 */
final class JobRunner
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function run(JobInterface $job): void
    {
        if (!method_exists($job, 'handle')) {
            throw new LogicException(sprintf('The job %s has no handle() method.', $job::class));
        }
        $method = new ReflectionMethod($job, 'handle');
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
                $arguments[] = $this->container->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $arguments[] = null;
            } else {
                throw new LogicException(sprintf(
                    '%s::handle() parameter $%s needs a class or interface type the container provides, or a default value. Keep job data in properties instead.',
                    $job::class,
                    $parameter->getName(),
                ));
            }
        }
        $method->invokeArgs($job, $arguments);
    }
}
