<?php

/** @var Sunrise\Http\Router\RouteCollector $this */

// or you can use an anonymous function as your request handler:
$this->group( function (\Sunrise\Http\Router\RouteCollector $collector){
    $this->get('home', '/', [\App\Http\Controller\HomeController::class, 'index'], [\App\Http\Middleware\SessionMiddleware::class]);
});
