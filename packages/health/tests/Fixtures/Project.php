<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Health\Health;
use Crystoline\Xpsr\Health\HealthServiceRegistry;
use Crystoline\Xpsr\Health\Report;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\Lock\LockServiceRegistry;
use Crystoline\Xpsr\Queue\Console\MakeQueueTableCommand;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Queue\WorkerHeartbeat;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\Scheduler\Console\ScheduleRunCommand;
use Crystoline\Xpsr\Scheduler\SchedulerServiceRegistry;
use Doctrine\DBAL\Connection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A temporary project with the two health routes, SQLite, the array cache, in-memory locks,
 * a queue with a sync and a database transport and a failed jobs table, the scheduler, a fake
 * HTTP client, and the health provider. boot() creates the queue tables.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;
    public readonly MockClock $clock;
    public readonly FakeClient $client;

    /**
     * @param array<string, mixed> $health the "health" configuration
     * @param array<string, array<string, mixed>>|null $connections database.connections, over the default SQLite one
     * @param bool $databaseTransport with the "db" transport beside the sync one
     */
    public function __construct(array $health = [], ?array $connections = null, bool $databaseTransport = true)
    {
        $this->root = sys_get_temp_dir() . '/xpsr-health-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/routes');
        mkdir($this->root . '/migrations');
        file_put_contents($this->root . '/routes/web.php', <<<'PHP'
            <?php
            use Crystoline\Xpsr\Health\Http\HealthController;
            use Crystoline\Xpsr\Routing\RouteCollection;

            return static function (RouteCollection $routes): void {
                $routes->get('/health', [HealthController::class, 'live']);
                $routes->get('/health/ready', [HealthController::class, 'ready']);
            };
            PHP);
        $this->clock = FixtureRegistry::$clock = new MockClock('2026-11-03 10:00:00', 'UTC');
        $this->client = FixtureRegistry::$client = new FakeClient();
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.name', 'Shop');
        $config->set('app.env', 'local');
        $config->set('app.trusted_proxies', ['10.0.0.1']);
        $config->set('app.registry', [
            FixtureRegistry::class,
            RouteServiceRegistry::class,
            EventServiceRegistry::class,
            ConsoleServiceRegistry::class,
            DatabaseServiceRegistry::class,
            CacheServiceRegistry::class,
            LockServiceRegistry::class,
            QueueServiceRegistry::class,
            SchedulerServiceRegistry::class,
            HealthServiceRegistry::class,
        ]);
        $config->set('routing', ['adapter' => 'fastroute', 'files' => ['routes/web.php']]);
        $suffix = bin2hex(random_bytes(3));
        $config->set('database', [
            'default' => 'main',
            'connections' => $connections ?? ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['table' => "migrations_{$suffix}"],
        ]);
        $config->set('cache', ['driver' => 'array']);
        $config->set('lock', ['store' => 'in-memory']);
        $transports = ['default' => ['dsn' => 'sync://']];
        if ($databaseTransport) {
            $transports['db'] = ['dsn' => 'database://main?queue_name=jobs', 'retry' => ['max_retries' => 0]];
        }
        $config->set('queue', ['transports' => $transports, 'table' => "queue_{$suffix}", 'failed' => ['table' => "failed_{$suffix}"]]);
        $config->set('health', $health);
    }

    public function boot(): Application
    {
        $this->application->boot();
        $tester = new CommandTester($this->application->get(MakeQueueTableCommand::class));
        if ($tester->execute([]) !== 0) {
            throw new \RuntimeException('make:queue-table failed: ' . $tester->getDisplay());
        }
        $this->application->get(MigratorFactory::class)->create()->migrate();

        return $this->application;
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $uri, array $headers = [], string $remoteAddress = '203.0.113.9'): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'http://shop.example' . $uri, ['REMOTE_ADDR' => $remoteAddress]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->application->getHttpKernel()->handle($request);
    }

    public function health(): Health
    {
        return $this->application->get(Health::class);
    }

    public function report(bool $fresh = true): Report
    {
        return $this->health()->check($fresh);
    }

    public function cache(): CacheItemPoolInterface
    {
        return $this->application->get(CacheItemPoolInterface::class);
    }

    public function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    /** Leaves the heartbeats a worker on "db" and schedule:run would leave, now. */
    public function heartbeats(): void
    {
        (new WorkerHeartbeat($this->cache(), ['db'], $this->clock))->beat();
        $tester = new CommandTester($this->application->get(ScheduleRunCommand::class));
        $tester->execute([]);
    }

    public function remove(): void
    {
        $this->db()->close();
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
