<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A temporary project with the database and Eloquent providers. The default connection is the
 * server in XPSR_TEST_PGSQL_URL or XPSR_TEST_MYSQL_URL when set, otherwise SQLite; "other" is a
 * second SQLite database.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/xpsr-eloquent-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/migrations');
        mkdir($this->root . '/app');
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, AuthServiceRegistry::class, EloquentServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => [
                'main' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/main.sqlite'],
                'other' => ['driver' => 'pdo_sqlite', 'path' => 'var/other.sqlite'],
            ],
            'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))],
        ]);
    }

    public function boot(): Application
    {
        $this->application->boot();

        return $this->application;
    }

    public function db(?string $name = null): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection($name);
    }

    public function remove(): void
    {
        foreach (['main', 'other'] as $name) {
            $this->db($name)->close();
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
