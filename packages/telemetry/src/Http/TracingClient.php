<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Http;

use Closure;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Wraps a PSR-18 client: a client span per request, with the W3C traceparent and tracestate
 * headers added to the request so the called service continues the trace. A 4xx or 5xx
 * response, or a failure to send, marks the span as failed. The URL is recorded without its
 * credentials.
 *
 * TelemetryServiceRegistry wraps the default and named clients of crystoline/xpsr-http-client.
 */
final class TracingClient implements ClientInterface
{
    private ?Tracer $tracer = null;

    /** @param Tracer|Closure(): Tracer $tracerOrResolver the tracer, or a closure giving it, resolved on the first request, so wrapping a client does not build the tracer */
    public function __construct(private readonly ClientInterface $inner, private readonly Tracer|Closure $tracerOrResolver) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $tracer = $this->tracer();
        $uri = $request->getUri();
        $attributes = [
            Attributes::HTTP_REQUEST_METHOD => $request->getMethod(),
            Attributes::URL_FULL => (string) $uri->withUserInfo(''),
            Attributes::SERVER_ADDRESS => $uri->getHost(),
        ];
        if ($uri->getPort() !== null) {
            $attributes[Attributes::SERVER_PORT] = $uri->getPort();
        }
        $span = $tracer->start($request->getMethod(), $attributes, SpanKind::KIND_CLIENT);
        $headers = [];
        $tracer->inject($headers);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        try {
            $response = $this->inner->sendRequest($request);
            $span->setAttribute(Attributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
            if ($response->getStatusCode() >= 400) {
                $span->setAttribute(Attributes::ERROR_TYPE, (string) $response->getStatusCode());
                $span->setError('HTTP ' . $response->getStatusCode());
            }

            return $response;
        } catch (Throwable $exception) {
            $span->recordException($exception);
            throw $exception;
        } finally {
            $span->end();
        }
    }

    /** The wrapped client, for requests that must not be traced, such as the export itself. */
    public function inner(): ClientInterface
    {
        return $this->inner;
    }

    private function tracer(): Tracer
    {
        return $this->tracer ??= $this->tracerOrResolver instanceof Tracer ? $this->tracerOrResolver : ($this->tracerOrResolver)();
    }
}
