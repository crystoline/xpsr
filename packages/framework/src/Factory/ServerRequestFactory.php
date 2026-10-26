<?php

declare(strict_types=1);

namespace Crystoline\Factory;

use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

class ServerRequestFactory
{
    public function __invoke(ContainerInterface $container): ServerRequestInterface
    {
        $creator = new ServerRequestCreator(
            $container->get(ServerRequestFactoryInterface::class),
            $container->get(UriFactoryInterface::class),
            $container->get(UploadedFileFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
        );

        return $creator->fromGlobals();
    }
}
