<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\Log\Monolog\Http\RequestIdMiddleware;
use Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\Scheduler\SchedulerServiceRegistry;
use Crystoline\Xpsr\Telemetry\Http\TraceRequestsMiddleware;
use Crystoline\Xpsr\Telemetry\Telemetry;
use Crystoline\Xpsr\Telemetry\TelemetryServiceRegistry;
use Crystoline\Xpsr\Telemetry\Testing\FakeTelemetry;
use Doctrine\DBAL\Connection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Clock\MockClock;

/**
 * A temporary project with routes, SQLite, Monolog logging to a file, the sync queue, the
 * scheduler, fake HTTP clients, and telemetry on with the memory exporter unless the "telemetry"
 * configuration says otherwise. boot() creates the telemetry_payments table with one row and
 * registers the test:echo command.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;
    public readonly MockClock $clock;
    public readonly FakeClient $client;
    public readonly FakeClient $payments;
    public readonly Recorder $recorder;

    /**
     * @param array<string, mixed> $telemetry the "telemetry" configuration, over the defaults
     * @param list<string>|null $middleware app.middleware; by default the request ID middleware, then the tracing one
     */
    public function __construct(array $telemetry = [], ?array $middleware = null)
    {
        $this->root = sys_get_temp_dir() . '/xpsr-telemetry-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/routes');
        mkdir($this->root . '/migrations');
        file_put_contents($this->root . '/routes/web.php', <<<'PHP'
            <?php
            use Crystoline\Xpsr\Routing\RouteCollection;
            use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Controllers;

            return static function (RouteCollection $routes): void {
                $routes->get('/hello', [Controllers::class, 'hello'])->name('hello');
                $routes->get('/payments/{id}', [Controllers::class, 'payment'])->name('payments.show');
                $routes->get('/boom', [Controllers::class, 'boom']);
                $routes->get('/call', [Controllers::class, 'call']);
                $routes->post('/queue', [Controllers::class, 'queue']);
                $routes->post('/queue-failing', [Controllers::class, 'queueFailing']);
            };
            PHP);
        $this->clock = FixtureRegistry::$clock = new MockClock('2026-11-02 10:00:00', 'UTC');
        $this->client = FixtureRegistry::$client = new FakeClient();
        $this->payments = FixtureRegistry::$payments = new FakeClient();
        $this->recorder = FixtureRegistry::$recorder = new Recorder();
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.name', 'Shop');
        $config->set('app.env', 'local');
        $config->set('app.trusted_proxies', ['10.0.0.0/8']);
        $config->set('app.middleware', $middleware ?? [RequestIdMiddleware::class, TraceRequestsMiddleware::class]);
        $config->set('app.registry', [
            FixtureRegistry::class,
            RouteServiceRegistry::class,
            EventServiceRegistry::class,
            ConsoleServiceRegistry::class,
            DatabaseServiceRegistry::class,
            MonologServiceRegistry::class,
            QueueServiceRegistry::class,
            SchedulerServiceRegistry::class,
            TelemetryServiceRegistry::class,
        ]);
        $config->set('routing', ['adapter' => 'fastroute', 'files' => ['routes/web.php']]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))],
        ]);
        $config->set('logging', ['channel' => 'file', 'format' => 'json', 'path' => 'var/log/app.log']);
        $config->set('queue', ['transports' => ['default' => ['dsn' => 'sync://']], 'failed' => false]);
        $config->set('http_client', ['clients' => ['payments' => ['base_uri' => 'http://payments.example']]]);
        $config->set('telemetry', $telemetry + ['enabled' => true, 'exporter' => 'memory', 'batch' => false]);
    }

    public function boot(): Application
    {
        $this->application->boot();
        $this->application->get(CommandRegistry::class)->register('test:echo', EchoCommand::class, 'Echo a word.');
        $this->db()->executeStatement('CREATE TABLE telemetry_payments (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $this->db()->executeStatement('INSERT INTO telemetry_payments (id, amount) VALUES (1, 500)');
        if ($this->application->get(Telemetry::class)->enabled() && $this->application->get(Telemetry::class)->options()->exporter === 'memory') {
            $this->spans()->clear();
        }

        return $this->application;
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $uri, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'http://shop.example' . $uri, ['REMOTE_ADDR' => '203.0.113.9']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->application->getHttpKernel()->handle($request);
    }

    public function spans(): FakeTelemetry
    {
        return $this->application->get(FakeTelemetry::class);
    }

    public function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    /** @return list<array<string, mixed>> the JSON log lines */
    public function logLines(): array
    {
        $file = $this->root . '/var/log/app.log';
        if (!is_file($file)) {
            return [];
        }
        $lines = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    public function remove(): void
    {
        $this->application->get(Telemetry::class)->shutdown();
        $this->db()->close();
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
