<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives each request its browser's session (Session::of($request)) and saves it afterwards.
 *
 * A session is stored, and its cookie sent, only once it holds something: a value or a CSRF
 * token. Pages that never touch the session set no cookie. When a handler throws, the session's
 * changes in that request are not saved.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly CurrentSession $current,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $name = $this->sessions->options()->cookieName;
        $cookie = $request->getCookieParams()[$name] ?? null;
        $session = $this->sessions->load(is_string($cookie) ? $cookie : null);

        $previous = $this->current->get();
        $this->current->set($session);
        try {
            $response = $handler->handle($request->withAttribute(Session::class, $session));
        } finally {
            $this->current->set($previous);
        }

        $sent = $this->sessions->save($session, $cookie !== null, $request->getUri()->getScheme() === 'https');
        $this->sessions->maybePrune();

        return $sent === null ? $response : $sent->addTo($response);
    }
}
