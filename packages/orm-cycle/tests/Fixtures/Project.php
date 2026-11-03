<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Console\MakeMigrationCommand;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Orm\Cycle\CycleOrmServiceRegistry;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Doctrine\DBAL\Connection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/** A temporary project with the database and Cycle ORM providers. */
final class Project
{
    public readonly string $root;
    public readonly Application $application;

    /**
     * @param array<string, mixed> $cycle the "cycle" configuration (entities: Fixtures/Entity unless set)
     * @param array<string, mixed> $connection DBAL parameters of the default connection
     * @param bool $auth with the auth provider
     * @param string|null $root reuse another project's directory, for a second application on it
     */
    public function __construct(
        array $cycle = [],
        array $connection = ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite'],
        bool $debug = true,
        bool $auth = false,
        ?string $root = null,
    ) {
        $this->root = $root ?? sys_get_temp_dir() . '/xpsr-cycle-' . bin2hex(random_bytes(6));
        foreach (['config', 'migrations', 'app'] as $directory) {
            if (!is_dir($this->root . '/' . $directory)) {
                mkdir($this->root . '/' . $directory, 0777, true);
            }
        }
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.debug', $debug);
        $registry = [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class];
        if ($auth) {
            $registry[] = AuthServiceRegistry::class;
        }
        $registry[] = CycleOrmServiceRegistry::class;
        $config->set('app.registry', $registry);
        $config->set('database', ['default' => 'main', 'connections' => ['main' => $connection], 'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))]]);
        $config->set('cycle', $cycle + ['entities' => [__DIR__ . '/Entity']]);
    }

    public function boot(): Application
    {
        $this->application->boot();

        return $this->application;
    }

    /** Creates the entities' tables through make:migration --diff and migrate. */
    public function migrateEntities(): void
    {
        $tester = new CommandTester($this->application->get(MakeMigrationCommand::class));
        $tester->execute(['name' => 'create_entities', '--diff' => true], ['interactive' => false, 'capture_stderr_separately' => true]);
        if ($tester->getStatusCode() !== 0) {
            throw new RuntimeException('make:migration --diff failed: ' . $tester->getDisplay() . $tester->getErrorOutput());
        }
        $this->application->get(MigratorFactory::class)->create()->migrate();
    }

    /** Forgets the pending changes and the loaded entities, as between requests. */
    public function reset(): void
    {
        $this->em()->clean();
        $this->orm()->getHeap()->clean();
    }

    public function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    public function orm(): ORMInterface
    {
        return $this->application->get(ORMInterface::class);
    }

    public function em(): EntityManagerInterface
    {
        return $this->application->get(EntityManagerInterface::class);
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
