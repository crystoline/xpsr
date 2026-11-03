<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests;

use Crystoline\Xpsr\Health\Checks\CacheCheck;
use Crystoline\Xpsr\Health\Checks\DiskSpaceCheck;
use Crystoline\Xpsr\Health\Checks\HttpCheck;
use Crystoline\Xpsr\Health\Checks\LocksCheck;
use Crystoline\Xpsr\Health\Status;
use Crystoline\Xpsr\Health\Tests\Fixtures\Project;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\Lock\Locks;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class ChecksTest extends TestCase
{
    public function testDiskSpaceThresholds(): void
    {
        $check = static fn(float $free, float $warning = 10, float $failing = 5): DiskSpaceCheck => new DiskSpaceCheck(sys_get_temp_dir(), $warning, $failing, static fn(): array => [$free, 100 * 1024 ** 3]);
        $ok = $check(50 * 1024 ** 3)->check();
        self::assertSame(Status::Ok, $ok->status);
        self::assertSame('50.0% free (50.0 GB of 100.0 GB)', $ok->message);
        self::assertSame(['path' => sys_get_temp_dir(), 'free_bytes' => 50 * 1024 ** 3, 'total_bytes' => 100 * 1024 ** 3, 'free_percent' => 50.0], $ok->details);
        self::assertSame(Status::Warning, $check(8 * 1024 ** 3)->check()->status);
        self::assertSame(Status::Failing, $check(3 * 1024 ** 3)->check()->status);
        self::assertSame(Status::Ok, $check(3 * 1024 ** 3, 2, 1)->check()->status);

        $unreadable = (new DiskSpaceCheck(sys_get_temp_dir(), 10, 5, static fn(): array => [false, false]))->check();
        self::assertSame(Status::Failing, $unreadable->status);
        self::assertStringStartsWith('Cannot read the disk space of', $unreadable->message);
        $missing = (new DiskSpaceCheck('/nonexistent/directory', 10, 5))->check();
        self::assertSame(Status::Failing, $missing->status);
        self::assertSame('"/nonexistent/directory" is not a directory', $missing->message);
        self::assertSame(Status::Ok, (new DiskSpaceCheck(sys_get_temp_dir(), 0, 0))->check()->status, 'The real filesystem.');
    }

    public function testHttpDependencies(): void
    {
        $client = new FakeClient(new Response(200), new Response(503), static function (RequestInterface $request): never {
            throw new class ('Connection refused.', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        });
        $check = new HttpCheck($client, new Psr17Factory(), ['gateway' => 'https://api.gateway.example/health', 'sms' => 'https://sms.example/ping', 'kyc' => 'https://kyc.example/']);
        $result = $check->check();
        self::assertSame(Status::Failing, $result->status);
        self::assertSame('Sms answered 503; kyc: Connection refused.', $result->message);
        self::assertSame(200, $result->details['gateway']['status']);
        self::assertSame('https://api.gateway.example/health', $result->details['gateway']['url']);
        self::assertArrayHasKey('milliseconds', $result->details['gateway']);
        self::assertSame(['url' => 'https://kyc.example/', 'error' => 'Connection refused.'], $result->details['kyc']);
        self::assertSame('GET', $client->requests()[0]->getMethod());

        $client->queue(new Response(204));
        $one = (new HttpCheck($client, new Psr17Factory(), ['gateway' => 'https://api.gateway.example/health']))->check();
        self::assertSame(Status::Ok, $one->status);
        self::assertSame('The dependency answers', $one->message);
    }

    public function testTheCacheCheckNoticesACacheThatForgets(): void
    {
        self::assertSame(Status::Ok, (new CacheCheck(new Psr16Cache(new ArrayAdapter())))->check()->status);
        $forgetful = new Psr16Cache(new NullAdapter());
        $result = (new CacheCheck($forgetful))->check();
        self::assertSame(Status::Failing, $result->status);
        self::assertSame('The cache did not return what was written', $result->message);
        self::assertNull($result->details['read']);
    }

    public function testTheLocksCheckWarnsWhileTheProbeLockIsHeld(): void
    {
        $project = new Project();
        $project->boot();
        try {
            $locks = $project->application->get(Locks::class);
            self::assertSame(Status::Ok, (new LocksCheck($locks))->check()->status);
            $held = $locks->lock(LocksCheck::KEY, 30);
            self::assertTrue($held->acquire());
            $result = (new LocksCheck($locks))->check();
            self::assertSame(Status::Warning, $result->status);
            self::assertStringContainsString('held', $result->message);
            $held->release();
            self::assertSame(Status::Ok, (new LocksCheck($locks))->check()->status);
        } finally {
            $project->remove();
        }
    }
}
