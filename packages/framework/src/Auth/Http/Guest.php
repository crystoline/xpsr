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
 * For pages only guests need, such as the login form: a browser that is signed in on the default
 * guard is sent to auth.home_path instead.
 */
final class Guest implements MiddlewareInterface
{
    public function __construct(private readonly AuthManager $auth, private readonly ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->authenticate($request) !== null) {
            return $this->responses->createResponse(302)->withHeader('Location', $this->auth->options()->homePath);
        }

        return $handler->handle($request);
    }
}
