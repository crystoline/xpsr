<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Http;

use Crystoline\Xpsr\Auth\AuthManager;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * For pages only guests need, such as the login form: a browser that is signed in on the guard is
 * sent to the guard's home_path instead.
 *
 *     ->middleware(Guest::class)             // the default guard
 *     ->middleware(Guest::guard('admin'))    // another guard
 */
final class Guest implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?string $guard = null,
    ) {}

    /** The middleware service for a guard: Guest::guard('admin'). */
    public static function guard(string $name): string
    {
        return "auth.guest.{$name}";
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->authenticate($request, $this->guard) !== null) {
            return $this->responses->createResponse(302)->withHeader('Location', $this->auth->options()->homePath($this->guard));
        }

        return $handler->handle($request);
    }
}
