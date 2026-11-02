<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

use Crystoline\Xpsr\Api\ApiServiceRegistry;
use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry;
use Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A temporary project with the database, Doctrine, Eloquent, and API providers on SQLite. The
 * Doctrine entities and the Eloquent models share the api_invoices and api_lines tables.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;

    /** @param array<string, mixed> $api the "api" configuration */
    public function __construct(array $api = [])
    {
        $this->root = sys_get_temp_dir() . '/xpsr-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/migrations');
        mkdir($this->root . '/app');
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.registry', [FactoriesRegistry::class, ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, DoctrineOrmServiceRegistry::class, EloquentServiceRegistry::class, ApiServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))],
        ]);
        $config->set('orm', ['entities' => [__DIR__ . '/Entity']]);
        $config->set('api', $api);
    }

    /** Boots, and creates the tables from the entities. */
    public function boot(): Application
    {
        $this->application->boot();
        $em = $this->em();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $this->application;
    }

    public function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    public function em(): EntityManagerInterface
    {
        return $this->application->get(EntityManagerInterface::class);
    }

    /** Three invoices, A1 to A3, with two lines each. */
    public function seed(): void
    {
        $em = $this->em();
        foreach (['A1', 'A2', 'A3'] as $i => $number) {
            $invoice = new Entity\Invoice($number);
            $invoice->addLine(($i + 1) * 100);
            $invoice->addLine(($i + 1) * 100 + 50);
            $em->persist($invoice);
        }
        $em->flush();
        $em->clear();
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
