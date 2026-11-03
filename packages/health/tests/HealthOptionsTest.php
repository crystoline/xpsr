<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests;

use Crystoline\Xpsr\Health\HealthOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthOptionsTest extends TestCase
{
    public function testDefaultsAndValues(): void
    {
        $defaults = HealthOptions::from([]);
        self::assertSame([], $defaults->checks);
        self::assertSame([], $defaults->skip);
        self::assertNull($defaults->secret);
        self::assertSame(5, $defaults->cache);
        self::assertSame(0, $defaults->failedJobs);
        self::assertSame(300, $defaults->workerStale);
        self::assertSame(180, $defaults->scheduleStale);
        self::assertNull($defaults->connections);
        self::assertNull($defaults->diskPath);
        self::assertSame(10.0, $defaults->diskWarning);
        self::assertSame(5.0, $defaults->diskFailing);
        self::assertSame([], $defaults->http);
        self::assertTrue($defaults->runs('disk'));

        self::assertSame('from-env', HealthOptions::from([], 'from-env')->secret);
        self::assertNull(HealthOptions::from([], '')->secret);

        $options = HealthOptions::from([
            'checks' => ['App\Health\GatewayCheck'],
            'skip' => ['disk'],
            'secret' => 'configured',
            'cache' => 0,
            'failed_jobs' => 3,
            'worker_stale' => '1 hour',
            'schedule_stale' => 90,
            'connections' => ['main'],
            'disk' => ['path' => '/var/data', 'warning' => 20, 'failing' => 2.5],
            'http' => ['gateway' => 'https://api.gateway.example/health'],
        ], 'from-env');
        self::assertSame(['App\Health\GatewayCheck'], $options->checks);
        self::assertFalse($options->runs('disk'));
        self::assertSame('configured', $options->secret, 'The configuration wins over the environment.');
        self::assertSame(0, $options->cache);
        self::assertSame(3, $options->failedJobs);
        self::assertSame(3600, $options->workerStale);
        self::assertSame(90, $options->scheduleStale);
        self::assertSame(['main'], $options->connections);
        self::assertSame('/var/data', $options->diskPath);
        self::assertSame(20.0, $options->diskWarning);
        self::assertSame(2.5, $options->diskFailing);
        self::assertSame(['gateway' => 'https://api.gateway.example/health'], $options->http);
        self::assertSame(172800, HealthOptions::seconds('2 days', 'x'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown option' => [['check' => []], 'Unknown option health.check. Known options: checks, skip, secret'];
        yield 'checks' => [['checks' => 'App\Health\GatewayCheck'], 'health.checks must be a list of check service IDs or class names.'];
        yield 'skip' => [['skip' => ['disk' => true]], 'health.skip must be a list of check names.'];
        yield 'secret' => [['secret' => 7], 'health.secret (or HEALTH_SECRET) must be a string.'];
        yield 'cache' => [['cache' => '5'], 'health.cache must be a whole number of seconds, 0 or more.'];
        yield 'failed jobs' => [['failed_jobs' => -1], 'health.failed_jobs must be a whole number of jobs, 0 or more.'];
        yield 'worker stale' => [['worker_stale' => 'soon'], 'health.worker_stale must be a number of seconds above zero, or a period such as "5 minutes".'];
        yield 'schedule stale' => [['schedule_stale' => 0], 'health.schedule_stale must be a number of seconds above zero, or a period such as "5 minutes".'];
        yield 'connections' => [['connections' => 'main'], 'health.connections must be a list of connection names.'];
        yield 'disk' => [['disk' => 'full'], 'health.disk must be an array with path, warning, and failing.'];
        yield 'disk option' => [['disk' => ['free' => 1]], 'Unknown option health.disk.free. Known options: path, warning, failing.'];
        yield 'disk path' => [['disk' => ['path' => '']], 'health.disk.path must be a directory.'];
        yield 'disk percent' => [['disk' => ['warning' => 120]], 'health.disk.warning must be a percentage of free space, 0 to 100.'];
        yield 'disk order' => [['disk' => ['warning' => 5, 'failing' => 10]], 'health.disk.failing must not be above health.disk.warning.'];
        yield 'http' => [['http' => ['gateway' => 'api.gateway.example']], 'health.http must map names to http or https URLs.'];
        yield 'http name' => [['http' => ['https://api.gateway.example']], 'health.http must map names to http or https URLs.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidOptionsNameTheSetting(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        HealthOptions::from($config);
    }
}
