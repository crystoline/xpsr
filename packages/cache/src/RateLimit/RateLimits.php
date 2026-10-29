<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\RateLimit;

use Closure;
use Crystoline\Xpsr\Http\Exception\TooManyRequestsHttpException;
use InvalidArgumentException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limits named in config/rate_limit.php, applied from code to a key of your choice, such
 * as an email address, a user ID, or a phone number:
 *
 *     $key = 'email:' . strtolower($email);
 *     $rateLimits->attempt('login', $key);  // over the limit: 429 with Retry-After
 *     if (!$auth->attempt($request, $email, $password)) {
 *         // wrong password: the attempt stays counted
 *     }
 *     $rateLimits->reset('login', $key);    // signed in: forget the failed attempts
 *
 * The middleware counts by client address under "ip:<address>", so a key with another prefix
 * has its own count, even in a limit the middleware also uses.
 *
 * Each method throws InvalidArgumentException for a name that is not in rate_limit.limits, or
 * whose options are invalid.
 */
final class RateLimits
{
    /** @var array<string, RateLimiterFactoryInterface> */
    private array $limiters = [];

    /** @param Closure(string): RateLimiterFactoryInterface $build the limiter of a configured name */
    public function __construct(private readonly Closure $build) {}

    /**
     * Counts one attempt by $key. Over the limit, throws an exception that the exception handler
     * renders as 429, with Retry-After and the headers the middleware sends.
     *
     * @throws TooManyRequestsHttpException over the limit
     * @throws InvalidArgumentException for an unknown name or invalid options
     */
    public function attempt(string $name, string $key): Allowance
    {
        $allowance = $this->hit($name, $key);
        if (!$allowance->accepted) {
            throw new TooManyRequestsHttpException($allowance->retryAfter, "Rate limit \"{$name}\" exceeded.", null, $allowance->headers());
        }

        return $allowance;
    }

    /**
     * Counts one attempt by $key and returns what is left, without throwing. A refused attempt
     * is not counted, so a key gets attempts back as the interval passes, however often it tries.
     */
    public function hit(string $name, string $key): Allowance
    {
        return Allowance::of($this->limiter($name)->create($key)->consume());
    }

    /** How many attempts $key has left, without counting one. */
    public function remaining(string $name, string $key): int
    {
        return $this->limiter($name)->create($key)->consume(0)->getRemainingTokens();
    }

    /** Forgets the attempts counted for $key. */
    public function reset(string $name, string $key): void
    {
        $this->limiter($name)->create($key)->reset();
    }

    /**
     * The Symfony limiter factory of a named limit, for what the methods above do not cover,
     * such as reserve() and waiting for the allowance to return.
     */
    public function limiter(string $name): RateLimiterFactoryInterface
    {
        return $this->limiters[$name] ??= ($this->build)($name);
    }
}
