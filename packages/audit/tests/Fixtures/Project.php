<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Tests\Fixtures;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Audit\AuditLog;
use Crystoline\Xpsr\Audit\AuditServiceRegistry;
use Crystoline\Xpsr\Audit\Console\MakeAuditTableCommand;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\Orm\Doctrine\DoctrineOrmServiceRegistry;
use Crystoline\Xpsr\Orm\Eloquent\EloquentServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A temporary project with the database (SQLite), Doctrine, Eloquent, and audit providers, a
 * mock clock, a settable identity, and a request ID holder. boot() writes and runs the audit
 * migration and creates the fixture tables.
 */
final class Project
{
    public readonly string $root;
    public readonly Application $application;
    public readonly MockClock $clock;
    public readonly CurrentIdentity $identity;
    public readonly RequestId $requestId;

    /** @param array<string, mixed> $audit the "audit" configuration */
    public function __construct(array $audit = [])
    {
        $this->root = sys_get_temp_dir() . '/xpsr-audit-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/migrations');
        $this->clock = FixtureRegistry::$clock = new MockClock('2026-11-02 10:00:00', 'UTC');
        $this->identity = FixtureRegistry::$identity = new CurrentIdentity();
        $this->requestId = FixtureRegistry::$requestId = new RequestId();
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.env', 'local');
        $config->set('app.registry', [FixtureRegistry::class, ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, DoctrineOrmServiceRegistry::class, EloquentServiceRegistry::class, AuditServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite']],
            'migrations' => ['table' => 'migrations_' . bin2hex(random_bytes(3))],
        ]);
        $config->set('orm', ['entities' => [__DIR__ . '/Entity']]);
        $config->set('audit', $audit);
    }

    public function boot(): Application
    {
        $this->application->boot();
        $tester = new CommandTester($this->application->get(MakeAuditTableCommand::class));
        if ($tester->execute([]) !== 0) {
            throw new \RuntimeException('make:audit-table failed: ' . $tester->getDisplay());
        }
        $this->application->get(MigratorFactory::class)->create()->migrate();
        $em = $this->em();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->db()->createSchemaManager()->createTable(Table::editor()
            ->setUnquotedName('audit_payments')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('reference')->setTypeName(Types::STRING)->setLength(50)->create(),
                Column::editor()->setUnquotedName('amount')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('status')->setTypeName(Types::STRING)->setLength(20)->create(),
                Column::editor()->setUnquotedName('card_number')->setTypeName(Types::STRING)->setLength(30)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('notes')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('password')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create());

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

    public function log(): AuditLog
    {
        return $this->application->get(AuditLog::class);
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
