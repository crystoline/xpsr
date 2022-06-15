<?php

namespace Crystoline\Xpsr\Factory;

use Psr\Container\ContainerInterface;

class ServerRequestFactory
{
    public function __invoke(ContainerInterface $container): \Psr\Http\Message\ServerRequestInterface
    {
        return \Sunrise\Http\ServerRequest\ServerRequestFactory::fromGlobals();
    }
}