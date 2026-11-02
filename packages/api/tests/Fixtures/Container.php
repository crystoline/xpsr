<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/** A container with a ResponseBuilder and whatever else a test gives it. */
final class Container implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services;

    /** @param array<string, object> $services */
    public function __construct(array $services = [])
    {
        $factory = new Psr17Factory();
        $this->services = $services + [ResponseBuilder::class => new ResponseBuilder($factory, $factory)];
    }

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new class ("No service {$id}.") extends \RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
