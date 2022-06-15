<?php

namespace Crystoline\Xpsr\Http\Router;

use Fig\Http\Message\RequestMethodInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

interface RouteHandlerInterface extends MiddlewareInterface, RequestHandlerInterface, RequestMethodInterface
{

}