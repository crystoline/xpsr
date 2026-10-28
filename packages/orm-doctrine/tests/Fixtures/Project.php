<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** A temporary project with the database and Doctrine ORM providers. */
final class Project
{
    public readonly string $root;
    public readonly Application $application;

    /**
     * @param array<string, mixed> $orm the "orm" configuration
     * @param array<string, mixed> $connection DBAL parameters of the default connection
     */
    public function __construct(array $orm = [], array $connection = ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite'], bool $debug = true)
    {
        $this->root = sys_get_temp_dir() . '/xpsr-orm-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/migrations');
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.debug', $debug);
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, DoctrineOrmServiceRegistry::class]);
        $config->set('database', ['default' => 'main', 'connections' => ['main' => $connection], 'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))]]);
        $config->set('orm', $orm + ['entities' => [__DIR__ . '/Entity']]);
    }

    public function boot(): Application
    {
        $this->application->boot();

        return $this->application;
    }

    public function remove(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
