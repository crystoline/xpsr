<?php

declare(strict_types=1);

use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Routes for tests/Integration/SessionApplicationTest.php: a small web application with forms.
return static function (RouteCollection $routes): void {
    $responses = static fn(): ResponseBuilder => app()->get(ResponseBuilder::class);

    $routes->group('', static function (RouteCollection $routes) use ($responses): void {
        $routes->get('/tasks/new', static fn(): ResponseInterface => view('form'));
        $routes->post('/tasks', static function (ServerRequestInterface $request) use ($responses): ResponseInterface {
            $body = (array) $request->getParsedBody();
            Session::of($request)->flash('status', 'Saved ' . ($body['title'] ?? '') . '.');

            return $responses()->redirect('/tasks');
        });
        $routes->get('/tasks', static fn(ServerRequestInterface $request): ResponseInterface => $responses()->json([
            'status' => Session::of($request)->get('status'),
        ]));
        $routes->post('/login', static function (ServerRequestInterface $request) use ($responses): ResponseInterface {
            $session = Session::of($request);
            $session->regenerate();
            $session->set('user_id', 7);

            return $responses()->redirect('/whoami');
        });
        $routes->post('/logout', static function (ServerRequestInterface $request) use ($responses): ResponseInterface {
            Session::of($request)->invalidate();

            return $responses()->redirect('/');
        });
        $routes->get('/whoami', static fn(ServerRequestInterface $request): ResponseInterface => $responses()->json([
            'user_id' => Session::of($request)->get('user_id'),
        ]));
    }, middleware: [SessionMiddleware::class, CsrfMiddleware::class]);

    // An API route: no session, no CSRF check.
    $routes->post('/api/echo', static fn(ServerRequestInterface $request): ResponseInterface => $responses()->json($request->getParsedBody()));
};
