<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects requests that change state (every method except GET, HEAD, OPTIONS, and TRACE) unless
 * they carry the session's CSRF token: in the "_token" field of a form or JSON body, or in the
 * X-CSRF-Token header. Anything else is a 403 (InvalidCsrfTokenException).
 *
 * Runs after SessionMiddleware. Attach both to the routes browsers use with cookies (the
 * skeleton's routes/web.php), not to API routes that authenticate each request with a token.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';
    private const SAFE = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = Session::of($request);
        if (!in_array(strtoupper($request->getMethod()), self::SAFE, true) && !$session->isValidToken(self::submitted($request))) {
            throw new InvalidCsrfTokenException();
        }

        return $handler->handle($request);
    }

    private static function submitted(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();
        $field = is_array($body) ? ($body[self::FIELD] ?? null) : null;
        if (is_string($field) && $field !== '') {
            return $field;
        }

        return $request->getHeaderLine(self::HEADER);
    }
}
