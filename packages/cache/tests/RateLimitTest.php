<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Tests;

use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Cache\RateLimit\ClientKeyResolverInterface;
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;
use Crystoline\Xpsr\Cache\RateLimit\RateLimitMiddleware;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Foundation\ServiceProviderManager;
use Crystoline\Xpsr\Http\Exception\TooManyRequestsHttpException;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RateLimitTest extends TestCase
{
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };
    }

    public function testRequestsWithinTheLimitPassAndCarryTheRemainingAllowance(): void
    {
        $limit = $this->middleware(['login' => ['limit' => 3, 'interval' => '1 minute']], 'login');

        foreach (['2', '1', '0'] as $remaining) {
            $response = $limit->process($this->request('203.0.113.7'), $this->handler);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('3', $response->getHeaderLine('X-RateLimit-Limit'));
            self::assertSame($remaining, $response->getHeaderLine('X-RateLimit-Remaining'));
        }

        try {
            $limit->process($this->request('203.0.113.7'), $this->handler);
            self::fail('The fourth request was let through.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            $headers = $exception->getHeaders();
            self::assertSame('3', $headers['X-RateLimit-Limit']);
            self::assertSame('0', $headers['X-RateLimit-Remaining']);
            self::assertGreaterThanOrEqual(1, (int) $headers['Retry-After']);
            // Rounded up, so a client never retries early: up to one second over the interval.
            self::assertLessThanOrEqual(61, (int) $headers['Retry-After']);
            self::assertStringContainsString('Rate limit "login" exceeded.', $exception->getMessage());
        }
    }

    public function testEachClientAndEachLimitHasItsOwnAllowance(): void
    {
        $container = $this->container(['login' => ['limit' => 1, 'interval' => '1 minute'], 'api' => ['limit' => 1, 'interval' => '1 minute']]);
        $login = $container->get(RateLimit::named('login'));
        $api = $container->get(RateLimit::named('api'));

        self::assertSame(200, $login->process($this->request('203.0.113.7'), $this->handler)->getStatusCode());
        self::assertSame(200, $login->process($this->request('203.0.113.8'), $this->handler)->getStatusCode(), 'Another client.');
        self::assertSame(200, $api->process($this->request('203.0.113.7'), $this->handler)->getStatusCode(), 'Another limit.');
        $this->expectException(TooManyRequestsHttpException::class);
        $login->process($this->request('203.0.113.7'), $this->handler);
    }

    public function testClientsBehindTrustedProxiesAreCountedByTheirOwnAddress(): void
    {
        $container = $this->container(['api' => ['limit' => 1, 'interval' => '1 minute']], ['10.0.0.0/8']);
        $api = $container->get(RateLimit::named('api'));
        $viaProxy = static fn(string $client): ServerRequest => new ServerRequest('GET', '/', ['X-Forwarded-For' => $client], null, '1.1', ['REMOTE_ADDR' => '10.0.0.2']);

        self::assertSame(200, $api->process($viaProxy('198.51.100.1'), $this->handler)->getStatusCode());
        self::assertSame(200, $api->process($viaProxy('198.51.100.2'), $this->handler)->getStatusCode(), 'The proxy address is not shared.');
        $this->expectException(TooManyRequestsHttpException::class);
        $api->process($viaProxy('198.51.100.1'), $this->handler);
    }

    public function testTheAllowanceRecoversOverTheInterval(): void
    {
        $limit = $this->middleware(['burst' => ['limit' => 1, 'interval' => '1 second']], 'burst');
        self::assertSame(200, $limit->process($this->request('203.0.113.7'), $this->handler)->getStatusCode());
        try {
            $limit->process($this->request('203.0.113.7'), $this->handler);
            self::fail('The second request was let through.');
        } catch (TooManyRequestsHttpException) {
        }
        usleep(2_100_000); // a sliding window needs the previous window to pass as well
        $recovered = $limit->process($this->request('203.0.113.7'), $this->handler);
        self::assertSame('0', $recovered->getHeaderLine('X-RateLimit-Remaining'), 'The request is let through again.');
    }

    public function testACustomKeyResolverDecidesWhoIsCounted(): void
    {
        $container = $this->container(['api' => ['limit' => 1, 'interval' => '1 minute', 'key' => 'api.keys']]);
        $container->instance('api.keys', new class implements ClientKeyResolverInterface {
            public function key(ServerRequestInterface $request): string
            {
                return 'token:' . $request->getHeaderLine('X-Api-Key');
            }
        });
        $api = $container->get(RateLimit::named('api'));
        $withKey = fn(string $key): ServerRequestInterface => $this->request('203.0.113.7')->withHeader('X-Api-Key', $key);

        self::assertSame(200, $api->process($withKey('a'), $this->handler)->getStatusCode());
        self::assertSame(200, $api->process($withKey('b'), $this->handler)->getStatusCode(), 'Same address, different key.');
        $this->expectException(TooManyRequestsHttpException::class);
        $api->process($withKey('a'), $this->handler);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidLimits(): iterable
    {
        yield 'not an array' => ['60 per minute', 'rate_limit.limits.api must be an array with "limit" and "interval".'];
        yield 'unknown option' => [['limit' => 1, 'interval' => '1 minute', 'per' => 'ip'], 'Unknown option rate_limit.limits.api.per. Known options: limit, interval, key.'];
        yield 'zero limit' => [['limit' => 0, 'interval' => '1 minute'], 'rate_limit.limits.api.limit must be a whole number of requests, 1 or more.'];
        yield 'string limit' => [['limit' => '60', 'interval' => '1 minute'], 'rate_limit.limits.api.limit must be a whole number'];
        yield 'no interval' => [['limit' => 60], 'rate_limit.limits.api.interval must be a period such as "1 minute"'];
        yield 'nonsense interval' => [['limit' => 60, 'interval' => 'soon'], 'rate_limit.limits.api.interval must be a period'];
        yield 'zero interval' => [['limit' => 60, 'interval' => '0 seconds'], 'rate_limit.limits.api.interval must be a period'];
        yield 'key service' => [['limit' => 60, 'interval' => '1 minute', 'key' => Configuration::class], 'rate_limit.limits.api.key must name a service implementing'];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsNameTheSetting(mixed $options, string $message): void
    {
        $container = $this->container(['api' => $options]);
        try {
            $container->get(RateLimit::named('api'));
            self::fail('A limit was built from invalid options.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testLimitNamesAreChecked(): void
    {
        self::assertSame('rate_limit.login', RateLimit::named('login'));
        $this->expectException(InvalidArgumentException::class);
        RateLimit::named('Log In');
    }

    /** @param array<string, mixed> $limits */
    private function middleware(array $limits, string $name): RateLimitMiddleware
    {
        return $this->container($limits)->get(RateLimit::named($name));
    }

    /**
     * @param array<string, mixed> $limits
     * @param list<string> $proxies
     */
    private function container(array $limits, array $proxies = []): ServiceContainer
    {
        $container = new ServiceContainer();
        $config = new Configuration();
        $config->set('cache', ['driver' => 'array']);
        $config->set('rate_limit', ['limits' => $limits]);
        $config->set('app.trusted_proxies', $proxies);
        $container->instance(Configuration::class, $config);
        (new ServiceProviderManager($container))->boot([CacheServiceRegistry::class]);

        return $container;
    }

    private function request(string $client): ServerRequest
    {
        return new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => $client]);
    }
}
