<?php

declare(strict_types=1);

use Crystoline\Configuration;
use Crystoline\Exception\ExceptionHandler;
use Crystoline\Exception\ExceptionHandlerInterface;
use Crystoline\Factory\ServerRequestFactory;
use Crystoline\Log\ErrorLogLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

return [
    RequestFactoryInterface::class => static fn() => new Psr17Factory(),
    ResponseFactoryInterface::class => static fn() => new Psr17Factory(),
    ServerRequestFactoryInterface::class => static fn() => new Psr17Factory(),
    StreamFactoryInterface::class => static fn() => new Psr17Factory(),
    UploadedFileFactoryInterface::class => static fn() => new Psr17Factory(),
    UriFactoryInterface::class => static fn() => new Psr17Factory(),
    // Writes through error_log(): the web server's error log, or stderr for the CLI and development server.
    LoggerInterface::class => static fn() => new ErrorLogLogger((string) env('LOG_LEVEL', LogLevel::DEBUG)),
    ExceptionHandlerInterface::class => static fn(ContainerInterface $container) => new ExceptionHandler(
        $container->get(ResponseFactoryInterface::class),
        $container->get(StreamFactoryInterface::class),
        $container->get(LoggerInterface::class),
        (bool) $container->get(Configuration::class)->get('app.debug', false),
    ),
    ServerRequestInterface::class => static fn(ContainerInterface $container) => (new ServerRequestFactory())($container),
];
