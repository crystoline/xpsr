<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Http;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\UnauthenticatedException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Recognizes the user by a guard and puts the Identity on the request (Identity::of($request)).
 *
 *     ->middleware(Authenticate::class)              // the default guard; guests are refused
 *     ->middleware(Authenticate::guard('api'))       // another guard
 *     ->middleware(Authenticate::optional())         // guests pass through without an identity
 *
 * A refused guest gets 401, with the guard's WWW-Authenticate challenge. On the session guard, a
 * browser asking for a page (GET or HEAD, not JSON) is sent to auth.login_path instead, and the
 * page is remembered for AuthManager::intended().
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly CurrentIdentity $current,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?string $guard = null,
        private readonly bool $required = true,
    ) {}

    /** The middleware service for a guard: Authenticate::guard('api'). */
    public static function guard(string $name): string
    {
        return "auth.authenticate.{$name}";
    }

    /** The middleware service that identifies users when it can and lets guests through. */
    public static function optional(?string $name = null): string
    {
        return $name === null ? 'auth.authenticate.optional' : "auth.authenticate.{$name}.optional";
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $this->auth->authenticate($request, $this->guard);
        if ($identity === null && $this->required) {
            return $this->refuse($request);
        }
        if ($identity !== null) {
            $request = $request->withAttribute(Identity::class, $identity);
        }
        $previous = $this->current->get();
        $this->current->set($identity ?? $previous);
        try {
            return $handler->handle($request);
        } finally {
            $this->current->set($previous);
        }
    }

    private function refuse(ServerRequestInterface $request): ResponseInterface
    {
        $challenge = $this->auth->guard($this->guard)->challenge($request);
        if ($challenge === null && in_array($request->getMethod(), ['GET', 'HEAD'], true) && !self::wantsJson($request)) {
            $this->auth->rememberIntended($request);

            return $this->responses->createResponse(302)->withHeader('Location', $this->auth->options()->loginPath);
        }
        throw new UnauthenticatedException($challenge);
    }

    private static function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));

        return str_contains($accept, 'json') || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }
}
