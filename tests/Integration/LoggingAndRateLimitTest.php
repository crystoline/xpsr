<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;
use Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware;
use Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The Monolog and rate-limit packages in the skeleton's full HTTP kernel: request IDs in the
 * error log, and a limit applied to every request through app.middleware.
 */
final class LoggingAndRateLimitTest extends TestCase
{
    private string $logs;

    protected function setUp(): void
    {
        $this->logs = TemporaryDirectory::create('logs');
        $application = $this->application();
        $config = $application->getConfig();
        $config->set('logging', ['channel' => 'file', 'format' => 'json', 'path' => $this->logs . '/app.log']);
        $config->set('cache', ['driver' => 'array']);
        $config->set('rate_limit.limits', ['api' => ['limit' => 2, 'interval' => '1 minute']]);
        $config->set('app.middleware', [RequestIdMiddleware::class, RateLimit::named('api'), ...$config->get('app.middleware')]);
        // The skeleton does not install these packages; register them as an application would.
        foreach ([MonologServiceRegistry::class, CacheServiceRegistry::class] as $class) {
            (new $class($application->getContainer()))->register();
        }
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->logs);
    }

    public function testRequestsAreLimitedAndCarryTheirIds(): void
    {
        $first = $this->get('/hello', ['X-Request-Id' => 'client-1']);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('client-1', $first->getHeaderLine('X-Request-Id'));
        self::assertSame(['2', '1'], [$first->getHeaderLine('X-RateLimit-Limit'), $first->getHeaderLine('X-RateLimit-Remaining')]);
        self::assertSame('0', $this->get('/hello')->getHeaderLine('X-RateLimit-Remaining'));

        $limited = $this->get('/hello', ['Accept' => 'application/json']);
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame(['message' => 'Too Many Requests'], json_decode((string) $limited->getBody(), true));
        self::assertGreaterThanOrEqual(1, (int) $limited->getHeaderLine('Retry-After'));
        self::assertSame(['2', '0'], [$limited->getHeaderLine('X-RateLimit-Limit'), $limited->getHeaderLine('X-RateLimit-Remaining')]);
    }

    public function testServerErrorsAreLoggedWithTheRequestId(): void
    {
        $failing = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new RuntimeException('Database is down');
            }
        };
        $this->application()->getConfig()->set('app.middleware', [RequestIdMiddleware::class, $failing]);

        $response = $this->get('/hello', ['X-Request-Id' => 'trace-500', 'Accept' => 'application/json']);
        self::assertSame(500, $response->getStatusCode());

        $record = json_decode((string) file_get_contents($this->logs . '/app.log'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ERROR', $record['level_name']);
        self::assertStringContainsString('Database is down', json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame('trace-500', $record['extra']['request_id'], 'The error line names the request.');
    }
}
