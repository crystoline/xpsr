<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\HttpClient\Testing;

use Closure;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for tests. It sends nothing over the network: each request receives the
 * next queued response, and every request is recorded.
 *
 *   $client = new FakeClient($factory->createResponse(200)->withBody(...));
 *   $client = new FakeClient(static fn (RequestInterface $request) => ...);
 *
 * A queued closure receives the request and returns a response, or throws to simulate a
 * failure, such as a PSR-18 NetworkExceptionInterface. A request with no response left fails
 * with a LogicException naming it, so unexpected requests do not pass silently.
 */
final class FakeClient implements ClientInterface
{
    /** @var list<ResponseInterface|Closure(RequestInterface): ResponseInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @param ResponseInterface|Closure(RequestInterface): ResponseInterface ...$responses */
    public function __construct(ResponseInterface|Closure ...$responses)
    {
        $this->queue = array_values($responses);
    }

    /** @param ResponseInterface|Closure(RequestInterface): ResponseInterface ...$responses */
    public function queue(ResponseInterface|Closure ...$responses): self
    {
        array_push($this->queue, ...array_values($responses));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue)
            ?? throw new LogicException(sprintf('FakeClient has no response left for %s %s.', $request->getMethod(), $request->getUri()));

        return $next instanceof Closure ? $next($request) : $next;
    }

    /** @return list<RequestInterface> every request sent, in order */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new LogicException('FakeClient has not sent any request.')];
    }

    /** The number of queued responses not used yet. */
    public function remaining(): int
    {
        return count($this->queue);
    }
}
