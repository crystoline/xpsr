<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** The PSR-17 factories the skeleton's config/factory.php provides, for ResponseBuilder. */
final class FactoriesRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $factory = new Psr17Factory();
        $this->container->instance(ResponseFactoryInterface::class, $factory);
        $this->container->instance(StreamFactoryInterface::class, $factory);
    }
}
