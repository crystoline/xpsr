<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;
use Crystoline\Xpsr\Cache\RateLimit\RateLimitMiddleware;
use Crystoline\Xpsr\Cache\RateLimit\RateLimits;
use Crystoline\Xpsr\Cache\Tests\Fixtures\RecordingStore;
use Crystoline\Xpsr\Http\Exception\TooManyRequestsHttpException;
use Crystoline\Xpsr\Lock\LockServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\PersistingStoreInterface;

final class RateLimitsTest extends TestCase
{
    private const KEY = 'email:ada@example.com';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr rate limits ' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testAttemptCountsUntilTheLimitThenAnswers429(): void
    {
        $limits = $this->rateLimits($this->application());
        foreach ([2, 1, 0] as $remaining) {
            $allowance = $limits->attempt('login', self::KEY);
            self::assertTrue($allowance->accepted);
            self::assertSame(3, $allowance->limit);
            self::assertSame($remaining, $allowance->remaining);
        }

        try {
            $limits->attempt('login', self::KEY);
            self::fail('The fourth attempt was accepted.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertStringContainsString('Rate limit "login" exceeded.', $exception->getMessage());
            $headers = $exception->getHeaders();
            self::assertSame('3', $headers['X-RateLimit-Limit']);
            self::assertSame('0', $headers['X-RateLimit-Remaining']);
            self::assertGreaterThanOrEqual(1, (int) $headers['Retry-After']);
            self::assertLessThanOrEqual(15 * 60 + 1, (int) $headers['Retry-After']);
        }
    }

    public function testHitReportsWhatIsLeftWithoutThrowing(): void
    {
        $limits = $this->rateLimits($this->application());

        $first = $limits->hit('login', self::KEY);
        self::assertTrue($first->accepted);
        self::assertSame(2, $first->remaining);
        self::assertSame(0, $first->retryAfter, 'Attempts remain.');
        self::assertSame(['X-RateLimit-Limit' => '3', 'X-RateLimit-Remaining' => '2'], $first->headers());

        $limits->hit('login', self::KEY);
        $last = $limits->hit('login', self::KEY);
        self::assertTrue($last->accepted, 'The last unit is still accepted.');
        self::assertSame(0, $last->remaining);
        self::assertGreaterThanOrEqual(1, $last->retryAfter, 'None remain, so the next attempt has to wait.');

        $refused = $limits->hit('login', self::KEY);
        self::assertFalse($refused->accepted);
        self::assertSame(0, $refused->remaining);
        self::assertGreaterThanOrEqual(1, $refused->retryAfter);
        self::assertLessThanOrEqual(15 * 60 + 1, $refused->retryAfter);
    }

    public function testRemainingChecksWithoutCounting(): void
    {
        $limits = $this->rateLimits($this->application());
        $checks = [$limits->remaining('login', self::KEY), $limits->remaining('login', self::KEY)];
        self::assertSame([3, 3], $checks);
        $limits->hit('login', self::KEY);
        self::assertSame(2, $limits->remaining('login', self::KEY));
    }

    public function testResetForgetsTheAttempts(): void
    {
        $limits = $this->rateLimits($this->application());
        for ($i = 0; $i < 3; $i++) {
            $limits->hit('login', self::KEY);
        }
        self::assertFalse($limits->hit('login', self::KEY)->accepted);

        $limits->reset('login', self::KEY);
        self::assertSame(3, $limits->remaining('login', self::KEY));
        self::assertSame(2, $limits->attempt('login', self::KEY)->remaining);
    }

    public function testEachKeyHasItsOwnCountAndTheMiddlewareCountsByAddress(): void
    {
        $application = $this->application();
        $limits = $this->rateLimits($application);
        $middleware = $application->get(RateLimit::named('login'));
        self::assertInstanceOf(RateLimitMiddleware::class, $middleware);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(204);
            }
        };

        $middleware->process(new ServerRequest('POST', '/login', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']), $handler);
        $limits->hit('login', 'email:grace@example.com');

        self::assertSame(2, $limits->remaining('login', 'ip:203.0.113.7'), 'The middleware counted the address.');
        self::assertSame(2, $limits->remaining('login', 'email:grace@example.com'));
        self::assertSame(3, $limits->remaining('login', self::KEY), 'Another key.');
    }

    public function testUnknownAndInvalidNamesAreRefused(): void
    {
        $limits = $this->rateLimits($this->application());
        try {
            $limits->attempt('signup', self::KEY);
            self::fail('An unknown limit was applied.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('No rate limit is named "signup". Add it to rate_limit.limits in config/rate_limit.php.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Log In" is not one');
        $limits->hit('Log In', self::KEY);
    }

    public function testInvalidOptionsNameTheSetting(): void
    {
        $limits = $this->rateLimits($this->application(['rate_limit' => ['limits' => ['login' => ['limit' => 0, 'interval' => '1 minute']]]]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rate_limit.limits.login.limit must be a whole number of requests, 1 or more.');
        $limits->hit('login', self::KEY);
    }

    public function testArrayAndNullCachesTakeNoLocks(): void
    {
        foreach (['array', 'null'] as $driver) {
            $this->rateLimits($this->application(['cache' => ['driver' => $driver]]))->hit('login', self::KEY);
        }
        self::assertDirectoryDoesNotExist($this->root . '/var/locks');
    }

    public function testWithoutTheLockPackageKeysShareFileLocksInVarLocks(): void
    {
        $limits = $this->rateLimits($this->application(['cache' => ['driver' => 'file']]));
        for ($i = 0; $i < 300; $i++) {
            $limits->hit('login', "email:{$i}@example.com");
        }

        $files = glob($this->root . '/var/locks/sf.xpsr_rate_limit.*.lock') ?: [];
        self::assertNotEmpty($files, 'Counts were locked.');
        self::assertLessThanOrEqual(256, count($files), 'A file lock leaves its file behind, so keys share 256 of them.');
        self::assertSame(2, $limits->remaining('login', 'email:0@example.com'));
    }

    public function testALockDirectoryThatCannotBeCreatedIsNamed(): void
    {
        file_put_contents($this->root . '/var', 'A file, so no directory can be made inside it.');
        $application = $this->application(['cache' => ['driver' => 'file', 'path' => 'cache']]);

        try {
            $application->get(RateLimits::class);
            self::fail('Rate limits were built without their locks.');
        } catch (ContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(LogicException::class, $previous);
            self::assertSame('Rate limits keep their locks in ' . realpath($this->root) . '/var/locks, which could not be created.', $previous->getMessage());
        }
    }

    public function testTheApplicationsLocksAreUsedWhenTheLockPackageIsListed(): void
    {
        $application = $this->application(['cache' => ['driver' => 'file']], locks: true);
        $store = new RecordingStore();
        $container = $application->getContainer();
        self::assertInstanceOf(ServiceContainer::class, $container);
        $container->instance(PersistingStoreInterface::class, $store);

        $this->rateLimits($application)->hit('login', self::KEY);

        $lock = 'app:xpsr_rate_limit_login-' . self::KEY;
        self::assertSame([$lock], $store->saved, 'One lock per key, with lock.prefix.');
        self::assertSame([$lock], $store->deleted, 'Released after counting.');
        self::assertSame([5.0], $store->ttls, 'A short lifetime, in case the process dies holding it.');
        self::assertDirectoryDoesNotExist($this->root . '/var/locks');
    }

    public function testAnUnreachableLockStoreFailsTheAttemptRatherThanLettingItThrough(): void
    {
        $application = $this->application(['cache' => ['driver' => 'file']], locks: true);
        $container = $application->getContainer();
        self::assertInstanceOf(ServiceContainer::class, $container);
        $container->instance(PersistingStoreInterface::class, new RecordingStore(unreachable: true));
        $limits = $this->rateLimits($application);

        try {
            $limits->hit('login', self::KEY);
            self::fail('The attempt was let through without a lock.');
        } catch (LockAcquiringException $exception) {
            self::assertInstanceOf(LockStorageException::class, $exception->getPrevious());
        }
    }

    public function testTheLockPackagesFileLocksAreSharedByKeysToo(): void
    {
        $limits = $this->rateLimits($this->application(['cache' => ['driver' => 'file']], locks: true));
        for ($i = 0; $i < 300; $i++) {
            $limits->hit('login', "email:{$i}@example.com");
        }

        $files = glob($this->root . '/var/locks/sf.app-xpsr_rate_limit.*.lock') ?: [];
        self::assertNotEmpty($files);
        self::assertLessThanOrEqual(256, count($files));
    }

    /** @param array<string, mixed> $config */
    private function application(array $config = [], bool $locks = false): Application
    {
        $application = new Application($this->root);
        $settings = $application->getConfig();
        $settings->set('app.registry', $locks ? [CacheServiceRegistry::class, LockServiceRegistry::class] : [CacheServiceRegistry::class]);
        $settings->set('cache', ['driver' => 'array']);
        $settings->set('rate_limit', ['limits' => ['login' => ['limit' => 3, 'interval' => '15 minutes']]]);
        foreach ($config as $key => $value) {
            $settings->set($key, $value);
        }
        $application->boot();

        return $application;
    }

    private function rateLimits(Application $application): RateLimits
    {
        $limits = $application->get(RateLimits::class);
        self::assertInstanceOf(RateLimits::class, $limits);

        return $limits;
    }
}
