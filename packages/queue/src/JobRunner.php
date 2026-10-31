<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue;

use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Runs a job's handle() method, passing each parameter from the container by its class or
 * interface type; parameters with a default value and no service get the default. After a job
 * fails for the last time, calls its failed() method the same way, with the exception first.
 */
final class JobRunner
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function run(JobInterface $job): void
    {
        if (!method_exists($job, 'handle')) {
            throw new LogicException(sprintf('The job %s has no handle() method.', $job::class));
        }
        $this->call($job, 'handle');
    }

    /**
     * Calls the job's failed() method, if it has one: failed(Throwable $error, ...services).
     * Returns whether the job has one.
     */
    public function fail(JobInterface $job, Throwable $error): bool
    {
        if (!method_exists($job, 'failed')) {
            return false;
        }
        $this->call($job, 'failed', $error);

        return true;
    }

    private function call(JobInterface $job, string $method, ?Throwable $error = null): void
    {
        $reflection = new ReflectionMethod($job, $method);
        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($error !== null && $parameter->getPosition() === 0) {
                $arguments[] = $error;
            } elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
                $arguments[] = $this->container->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $arguments[] = null;
            } else {
                throw new LogicException(sprintf(
                    '%s::%s() parameter $%s needs a class or interface type the container provides, or a default value. Keep job data in properties instead.',
                    $job::class,
                    $method,
                    $parameter->getName(),
                ));
            }
        }
        $reflection->invokeArgs($job, $arguments);
    }
}
