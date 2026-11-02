<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\HttpClient\Testing\FakeClient;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Webhooks\Console\MakeWebhooksTableCommand;
use Crystoline\Xpsr\Webhooks\Deliveries;
use Crystoline\Xpsr\Webhooks\Endpoints;
use Crystoline\Xpsr\Webhooks\Webhooks;
use Crystoline\Xpsr\Webhooks\WebhookServiceRegistry;
use Doctrine\DBAL\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A temporary project with the database (SQLite), queue (sync://), and webhooks providers, a
 * fake HTTP client, a recording event dispatcher, and a mock clock. boot() also writes and runs
 * the webhooks migration.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;
    public readonly FakeClient $client;
    public readonly RecordingDispatcher $events;
    public readonly MockClock $clock;

    /** @param array<string, mixed> $webhooks the "webhooks" configuration */
    public function __construct(array $webhooks = [])
    {
        $this->root = sys_get_temp_dir() . '/xpsr-webhooks-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/migrations');
        $this->client = FixtureRegistry::$client = new FakeClient();
        $this->events = FixtureRegistry::$events = new RecordingDispatcher();
        $this->clock = FixtureRegistry::$clock = new MockClock('2026-11-02 10:00:00', 'UTC');
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('app.registry', [FixtureRegistry::class, ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, QueueServiceRegistry::class, WebhookServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))],
        ]);
        $config->set('webhooks', $webhooks);
    }

    public function boot(): Application
    {
        $this->application->boot();
        $tester = new CommandTester($this->application->get(MakeWebhooksTableCommand::class));
        if ($tester->execute([]) !== 0) {
            throw new \RuntimeException('make:webhooks-table failed: ' . $tester->getDisplay());
        }
        $this->application->get(MigratorFactory::class)->create()->migrate();

        return $this->application;
    }

    public function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    public function webhooks(): Webhooks
    {
        return $this->application->get(Webhooks::class);
    }

    public function endpoints(): Endpoints
    {
        return $this->application->get(Endpoints::class);
    }

    public function deliveries(): Deliveries
    {
        return $this->application->get(Deliveries::class);
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
