<?php

declare(strict_types=1);

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Auth\Http\Guest;
use Crystoline\Xpsr\Auth\Http\RequireScopes;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\Jwt\JwtIssuer;
use Crystoline\Xpsr\Auth\UnauthenticatedException;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Routes for tests/Integration/AuthApplicationTest.php: browser sign-in and a JWT API.
return static function (RouteCollection $routes): void {
    $responses = static fn(): ResponseBuilder => app()->get(ResponseBuilder::class);
    $auth = static fn(): AuthManager => app()->get(AuthManager::class);

    $routes->group('', static function (RouteCollection $routes) use ($responses, $auth): void {
        $routes->get('/login', static fn(): ResponseInterface => view('login'))->middleware(Guest::class);
        $routes->post('/login', static function (ServerRequestInterface $request) use ($responses, $auth): ResponseInterface {
            $body = (array) $request->getParsedBody();
            if ($auth()->attempt($request, (string) ($body['email'] ?? ''), (string) ($body['password'] ?? '')) === null) {
                Session::of($request)->flash('error', 'Those details do not match.');

                return $responses()->redirect('/login');
            }

            return $responses()->redirect($auth()->intended($request));
        })->middleware(Guest::class);
        $routes->post('/logout', static function (ServerRequestInterface $request) use ($responses, $auth): ResponseInterface {
            $auth()->logout($request);

            return $responses()->redirect('/');
        });
        $routes->get('/account', static fn(): ResponseInterface => view('account'))->middleware(Authenticate::class);
    }, middleware: [SessionMiddleware::class, CsrfMiddleware::class, Authenticate::optional()]);

    $routes->group('/api', static function (RouteCollection $routes) use ($responses, $auth): void {
        $routes->post('/login', static function (ServerRequestInterface $request) use ($responses, $auth): ResponseInterface {
            $body = (array) $request->getParsedBody();
            $user = $auth()->verify((string) ($body['email'] ?? ''), (string) ($body['password'] ?? '')) ?? throw new UnauthenticatedException();
            $token = app()->get(JwtIssuer::class)->issue($user, ['me:read']);

            return $responses()->json(['token' => $token->token, 'expires_at' => $token->expiresAt->format(DATE_ATOM)]);
        });
        $routes->get('/me', static fn(ServerRequestInterface $request): ResponseInterface => $responses()->json([
            'id' => Identity::of($request)->user->authIdentifier(),
            'scopes' => Identity::of($request)->scopes(),
        ]))->middleware(Authenticate::guard('api'), RequireScopes::of('me:read'));
        $routes->delete('/me', static fn(): ResponseInterface => $responses()->json([]))
            ->middleware(Authenticate::guard('api'), RequireScopes::of('me:delete'));
    });
};
