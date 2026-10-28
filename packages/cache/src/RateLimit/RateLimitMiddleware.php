<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use Crystoline\Xpsr\Http\Exception\TooManyRequestsHttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Applies one named limit. Every request it sees uses one unit of the client's allowance:
 *
 * - Within the allowance, the request continues, and the response gets X-RateLimit-Limit and
 *   X-RateLimit-Remaining.
 * - Over it, a TooManyRequestsHttpException is thrown: the exception handler answers 429 with
 *   Retry-After (seconds) and the same two headers.
 *
 * Build it through the container service RateLimit::named($name).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly RateLimiterFactoryInterface $limiters,
        private readonly ClientKeyResolverInterface $keys,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $limit = $this->limiters->create($this->keys->key($request))->consume();
        $headers = [
            'X-RateLimit-Limit' => (string) $limit->getLimit(),
            'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
        ];
        if (!$limit->isAccepted()) {
            $retryAfter = (int) ceil($limit->getRetryAfter()->format('U.u') - microtime(true));
            throw new TooManyRequestsHttpException(max(1, $retryAfter), "Rate limit \"{$this->name}\" exceeded.", null, $headers);
        }
        $response = $handler->handle($request);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
