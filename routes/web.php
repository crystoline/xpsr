<?php

declare(strict_types=1);

use App\Http\Controller\HomeController;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\SessionMiddleware;

// Pages for browsers: each request has its session (Session::of($request)), and forms must send
// the CSRF token ({{ csrf_field() }} in a Twig form). API routes for token clients go in routes/api.php.
return static function (RouteCollection $routes): void {
    $routes->group('', static function (RouteCollection $routes): void {
        $routes->get('/', [HomeController::class, 'index'])->name('home');
    }, middleware: [SessionMiddleware::class, CsrfMiddleware::class]);
};
