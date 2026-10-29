<?php

declare(strict_types=1);

use App\Http\Controller\HomeController;
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\SessionMiddleware;

// Pages for browsers: each request has its session (Session::of($request)), forms must send the
// CSRF token ({{ csrf_field() }} in a Twig form), and a signed-in user is known
// (Identity::find($request), {{ auth_user() }}). Add ->middleware(Authenticate::class) to pages
// only signed-in users may see. API routes for token clients go in routes/api.php.
return static function (RouteCollection $routes): void {
    $routes->group('', static function (RouteCollection $routes): void {
        $routes->get('/', [HomeController::class, 'index'])->name('home');
    }, middleware: [SessionMiddleware::class, CsrfMiddleware::class, Authenticate::optional()]);
};
