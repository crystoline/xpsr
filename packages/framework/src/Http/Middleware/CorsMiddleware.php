<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http\Middleware;

use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Cross-origin resource sharing: lets browser code on the allowed origins call the application.
 * List it first in app.middleware, so preflight requests are answered before anything else runs.
 *
 * - A preflight request (OPTIONS with Origin and Access-Control-Request-Method) from an allowed
 *   origin is answered with 204 and the allowed methods and headers.
 * - Other requests from an allowed origin get Access-Control-Allow-Origin and the other response
 *   headers, error responses included: exceptions from later middleware or the route are
 *   rendered here, by the application's exception handler, so the browser can read them.
 * - Requests from other origins, and requests without Origin, pass through unchanged apart from
 *   Vary: Origin, which keeps shared caches from mixing answers for different origins.
 *
 * Options: config/cors.php, see CorsOptions.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CorsOptions $options,
        private readonly ResponseFactoryInterface $responses,
        private readonly ExceptionHandlerInterface $exceptions,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->options->enabled()) {
            return $handler->handle($request);
        }
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && $this->options->allows($origin);
        if ($allowed && $request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->preflight($request, $origin);
        }
        try {
            $response = $handler->handle($request);
        } catch (Throwable $exception) {
            if (!$allowed) {
                throw $exception;
            }
            $response = $this->exceptions->render($request, $exception);
        }
        $response = $this->vary($response, 'Origin');
        if (!$allowed) {
            return $response;
        }
        $response = $this->allowOrigin($response, $origin);
        if ($this->options->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->options->exposedHeaders));
        }

        return $response;
    }

    private function preflight(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        $response = $this->allowOrigin($this->responses->createResponse(204), $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->options->methods));
        $requested = trim($request->getHeaderLine('Access-Control-Request-Headers'));
        $headers = $this->options->headers === null ? $requested : implode(', ', $this->options->headers);
        if ($headers !== '') {
            $response = $response->withHeader('Access-Control-Allow-Headers', $headers);
        }
        if ($this->options->maxAge > 0) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->options->maxAge);
        }

        return $this->vary($response, 'Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers');
    }

    private function allowOrigin(ResponseInterface $response, string $origin): ResponseInterface
    {
        // "*" cannot be combined with credentials, so echo the origin instead.
        $value = $this->options->anyOrigin && !$this->options->credentials ? '*' : $origin;
        $response = $response->withHeader('Access-Control-Allow-Origin', $value);

        return $this->options->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }

    private function vary(ResponseInterface $response, string ...$headers): ResponseInterface
    {
        $present = array_map(strtolower(...), array_map(trim(...), explode(',', $response->getHeaderLine('Vary'))));
        foreach ($headers as $header) {
            if (!in_array(strtolower($header), $present, true) && !in_array('*', $present, true)) {
                $response = $response->withAddedHeader('Vary', $header);
            }
        }

        return $response;
    }
}
