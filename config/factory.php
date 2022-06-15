<?php

return [
    // system factory // Can be replaced

    Crystoline\Xpsr\Exception\ExceptionHandlerInterface::class => function() {return new Crystoline\Xpsr\Exception\ExceptionHandler ;},
    Psr\Http\Message\RequestInterface::class => Crystoline\Xpsr\Factory\HttpRequestFactory::class,
    Psr\Http\Message\ServerRequestInterface::class  => Crystoline\Xpsr\Factory\ServerRequestFactory::class,
];