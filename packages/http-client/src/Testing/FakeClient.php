<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\HttpClient\Testing;

use Closure;
use LogicException;
use PHPUnit\Framework\Assert;
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

    /**
     * A request was sent that matches: "GET https://api.example.com/charges" (the method and the
     * whole URL), or a closure that takes the request and returns true. At least one, or exactly
     * $times.
     *
     * @param string|Closure(RequestInterface): bool $request
     */
    public function assertRequested(string|Closure $request, ?int $times = null): void
    {
        $matching = $this->matching($request);
        $what = is_string($request) ? $request : 'that passes the check';
        if ($times === null) {
            Assert::assertNotEmpty($matching, "No request {$what} was sent." . $this->requestSummary());
        } else {
            Assert::assertCount($times, $matching, sprintf('Expected %d requests %s, not %d.%s', $times, $what, count($matching), $this->requestSummary()));
        }
    }

    /** @param string|Closure(RequestInterface): bool $request */
    public function assertNotRequested(string|Closure $request): void
    {
        $matching = $this->matching($request);
        Assert::assertCount(0, $matching, sprintf('%d request(s) %s were sent.', count($matching), is_string($request) ? $request : 'that pass the check'));
    }

    public function assertNothingRequested(): void
    {
        Assert::assertSame([], $this->requests, 'Requests were sent.' . $this->requestSummary());
    }

    /**
     * @param string|Closure(RequestInterface): bool $request
     * @return list<RequestInterface>
     */
    private function matching(string|Closure $request): array
    {
        return array_values(array_filter($this->requests, static fn(RequestInterface $sent): bool => is_string($request)
            ? strcasecmp($request, $sent->getMethod() . ' ' . $sent->getUri()) === 0
            : (bool) $request($sent)));
    }

    private function requestSummary(): string
    {
        $lines = array_map(static fn(RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri(), $this->requests);

        return $lines === [] ? ' Nothing was sent.' : ' Sent: ' . implode(', ', $lines) . '.';
    }

    /** The number of queued responses not used yet. */
    public function remaining(): int
    {
        return count($this->queue);
    }
}
