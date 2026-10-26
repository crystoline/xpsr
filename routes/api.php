<?php

declare(strict_types=1);

use Crystoline\Http\Response\ResponseBuilder;
use Crystoline\Routing\RouteCollection;
use Psr\Http\Message\ResponseInterface;

return static function (RouteCollection $routes): void {
    $routes->get('/hello', static function (): ResponseInterface {
        return app()->get(ResponseBuilder::class)->json(['helloooooooo']);
    })->name('hello');
};
