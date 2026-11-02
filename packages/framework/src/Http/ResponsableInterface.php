<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Something a route handler can return in place of a response, such as an API resource. The
 * handler resolver turns it into a PSR-7 response with the request being handled and the
 * application's container, from which it takes the services it needs (ResponseBuilder, options).
 */
interface ResponsableInterface
{
    public function toResponse(ServerRequestInterface $request, ContainerInterface $container): ResponseInterface;
}
