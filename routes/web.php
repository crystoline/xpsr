<?php

declare(strict_types=1);

use App\Http\Controller\HomeController;
use App\Http\Middleware\SessionMiddleware;
use Crystoline\Xpsr\Routing\RouteCollection;

return static function (RouteCollection $routes): void {
    $routes->get('/', [HomeController::class, 'index'])
        ->name('home')
        ->middleware(SessionMiddleware::class);
};
