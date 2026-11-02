<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Http;

use Closure;
use Crystoline\Xpsr\Http\ClientIp;
use Crystoline\Xpsr\Http\Exception\HttpExceptionInterface;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * A server span for each request, continuing the trace of an incoming traceparent header.
 * Named by the method until the route matches, then "GET /payments/{id}" (RouteListener).
 * A 5xx response or an exception marks the span as failed; a 4xx does not.
 *
 * List it first in app.middleware, so that every other middleware is inside the span. With
 * crystoline/xpsr-log-monolog, its RequestIdMiddleware may come before or after it: the request
 * ID is read when the request ends.
 */
final class TraceRequestsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $trustedProxies app.trusted_proxies, for the client address
     * @param (Closure(): ?string)|null $requestId the request ID of crystoline/xpsr-log-monolog
     */
    public function __construct(
        private readonly Tracer $tracer,
        private readonly bool $enabled,
        private readonly array $trustedProxies = [],
        private readonly ?Closure $requestId = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }
        $uri = $request->getUri();
        $attributes = [
            Attributes::HTTP_REQUEST_METHOD => $request->getMethod(),
            Attributes::URL_PATH => $uri->getPath(),
            Attributes::URL_SCHEME => $uri->getScheme(),
            Attributes::SERVER_ADDRESS => $uri->getHost(),
            Attributes::NETWORK_PROTOCOL_VERSION => $request->getProtocolVersion(),
        ];
        if ($uri->getQuery() !== '') {
            $attributes[Attributes::URL_QUERY] = $uri->getQuery();
        }
        if ($uri->getPort() !== null) {
            $attributes[Attributes::SERVER_PORT] = $uri->getPort();
        }
        if ($request->getHeaderLine('User-Agent') !== '') {
            $attributes[Attributes::USER_AGENT_ORIGINAL] = $request->getHeaderLine('User-Agent');
        }
        $ip = ClientIp::of($request, $this->trustedProxies);
        if ($ip !== null) {
            $attributes[Attributes::CLIENT_ADDRESS] = $ip;
        }
        $parent = $this->tracer->propagator()->extract($request, new HeaderCarrier(), Context::getCurrent());
        $span = $this->tracer->start($request->getMethod(), $attributes, SpanKind::KIND_SERVER, $parent);
        try {
            $response = $handler->handle($request);
            $span->setAttribute(Attributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                $span->setError('HTTP ' . $response->getStatusCode());
            }

            return $response;
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $span->setAttribute(Attributes::HTTP_RESPONSE_STATUS_CODE, $status);
            if ($status >= 500) {
                $span->recordException($exception);
            } else {
                $span->setAttribute(Attributes::ERROR_TYPE, $exception::class);
            }
            throw $exception;
        } finally {
            $id = $this->requestId === null ? null : ($this->requestId)();
            if ($id !== null) {
                $span->setAttribute(Attributes::XPSR_REQUEST_ID, $id);
            }
            $span->end();
        }
    }
}
