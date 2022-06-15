<?php

use Sunrise\Http\Message\ResponseFactory;

/** @var Sunrise\Http\Router\RouteCollector $this */

$this->get('', '/hello', function ($request) {
    return (new Sunrise\Http\Message\ResponseFactory)->createJsonResponse(200, ['helloooooooo']);
});

