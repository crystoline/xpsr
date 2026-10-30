<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Mail\MailServiceRegistry;
use Crystoline\Xpsr\Notifications\Console\MakeNotificationsTableCommand;
use Crystoline\Xpsr\Notifications\Console\MakePushSubscriptionsTableCommand;
use Crystoline\Xpsr\Notifications\Event\NotificationFailed;
use Crystoline\Xpsr\Notifications\Event\NotificationSent;
use Crystoline\Xpsr\Notifications\NotificationServiceRegistry;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\MemoryLogger;
use Crystoline\Xpsr\Notifications\Tests\Fixtures\Recorder;
use Crystoline\Xpsr\Queue\Console\QueueWorkCommand;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\Registry\ViewServiceRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An application with views, mail on array://, the database (SQLite, or the server in
 * XPSR_TEST_PGSQL_URL or XPSR_TEST_MYSQL_URL), queues, events, and notifications, in a temporary
 * directory. Tables get a random suffix and are dropped afterwards.
 */
abstract class NotificationTestCase extends TestCase
{
    protected const REGISTRIES = [
        ConsoleServiceRegistry::class,
        ViewServiceRegistry::class,
        EventServiceRegistry::class,
        MailServiceRegistry::class,
        DatabaseServiceRegistry::class,
        QueueServiceRegistry::class,
        NotificationServiceRegistry::class,
    ];

    protected string $root;
    protected string $suffix;
    protected MemoryLogger $logger;
    protected Recorder $recorder;
    protected MockClock $clock;
    protected ?Application $application = null;

    /** @var list<string> */
    protected array $tables = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-notifications-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/views/emails', 0777, true);
        mkdir($this->root . '/var', 0777, true);
        file_put_contents($this->root . '/views/emails/payment.twig', '<p>You received NGN {{ payment.amount }}.</p><p>Reference {{ payment.reference }}.</p>');
        $this->suffix = bin2hex(random_bytes(3));
        $this->logger = new MemoryLogger();
        $this->recorder = new Recorder();
        $this->clock = new MockClock('2026-10-30 13:00:00', 'Africa/Lagos');
    }

    protected function tearDown(): void
    {
        if ($this->application !== null && in_array(DatabaseServiceRegistry::class, (array) $this->application->getConfig()->get('app.registry'), true)) {
            $connection = $this->application->get(Connection::class);
            $schema = $connection->createSchemaManager();
            foreach ([...$this->tables, "migrations_{$this->suffix}"] as $table) {
                if ($schema->tablesExist([$table])) {
                    $schema->dropTable($table);
                }
            }
            $connection->close();
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @param array<string, mixed> $notifications config/notifications.php, over test defaults
     * @param array<string, mixed>|null $queue config/queue.php; default: sync:// and memory (in-memory://, serialized, one retry)
     * @param list<class-string>|null $registries
     * @param list<callable(object): void> $listeners for NotificationSent and NotificationFailed, after the recorder
     */
    protected function boot(array $notifications = [], ?array $queue = null, ?array $registries = null, array $listeners = []): Application
    {
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $application = new Application($this->root);
        $config = $application->getConfig();
        $config->set('app.registry', $registries ?? self::REGISTRIES);
        $config->set('app.view_dir', $this->root . '/views');
        $config->set('app.url', 'https://pay.example.com');
        $config->set('mail', ['dsn' => 'array://default', 'from' => 'hello@example.com']);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/notifications.sqlite']],
            'migrations' => ['path' => 'migrations', 'table' => "migrations_{$this->suffix}"],
        ]);
        $config->set('queue', $queue ?? [
            'transports' => [
                'default' => ['dsn' => 'sync://'],
                'memory' => ['dsn' => 'in-memory://?serialize=true', 'retry' => ['max_retries' => 1, 'delay' => 0]],
            ],
            'failed' => false,
        ]);
        $config->set('notifications', $notifications + [
            'sms' => ['dsn' => 'array://default'],
            'database' => ['table' => "notifications_{$this->suffix}"],
            'webpush' => ['table' => "push_{$this->suffix}"],
        ]);
        $record = function (object $event): void {
            $this->recorder->events[] = $event;
        };
        $config->set('events.listeners', [
            NotificationSent::class => [$record, ...$listeners],
            NotificationFailed::class => [$record, ...$listeners],
        ]);
        $application->getContainer()->instance(LoggerInterface::class, $this->logger);
        $application->getContainer()->instance(Recorder::class, $this->recorder);
        $application->getContainer()->instance(ClockInterface::class, $this->clock);
        $application->boot();

        return $this->application = $application;
    }

    /** Creates the notifications and push subscriptions tables with their make commands and migrate. */
    protected function migrate(Application $application): void
    {
        foreach ([MakeNotificationsTableCommand::class, MakePushSubscriptionsTableCommand::class] as $command) {
            $make = new CommandTester($application->get($command));
            self::assertSame(Command::SUCCESS, $make->execute([]), $make->getDisplay());
        }
        $this->tables = ["notifications_{$this->suffix}", "push_{$this->suffix}"];
        self::assertCount(2, $application->get(MigratorFactory::class)->create()->migrate());
    }

    /**
     * Creates the two tables as Laravel's migrations do: notifications (uuid('id'),
     * morphs('notifiable'), text('data'), timestamps) and the web push channel's
     * push_subscriptions (bigint IDs, a unique endpoint).
     */
    protected function laravelTables(Application $application): void
    {
        $this->tables = ["notifications_{$this->suffix}", "push_{$this->suffix}"];
        $schema = $application->get(Connection::class)->createSchemaManager();
        $timestamps = static fn(string ...$names): array => array_map(static fn(string $name): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::DATETIME_MUTABLE)->setNotNull(false)->create(), $names);
        $schema->createTable(
            Table::editor()
                ->setUnquotedName("notifications_{$this->suffix}")
                ->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName(Types::GUID)->create(),
                    Column::editor()->setUnquotedName('type')->setTypeName(Types::STRING)->setLength(255)->create(),
                    Column::editor()->setUnquotedName('notifiable_type')->setTypeName(Types::STRING)->setLength(255)->create(),
                    Column::editor()->setUnquotedName('notifiable_id')->setTypeName(Types::BIGINT)->setUnsigned(true)->create(),
                    Column::editor()->setUnquotedName('data')->setTypeName(Types::TEXT)->create(),
                    ...$timestamps('read_at', 'created_at', 'updated_at'),
                )
                ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->setIndexes(Index::editor()->setUnquotedName("notifications_{$this->suffix}_morph")->setUnquotedColumnNames('notifiable_type', 'notifiable_id')->create())
                ->create(),
        );
        $nullable = static fn(string $name): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create();
        $schema->createTable(
            Table::editor()
                ->setUnquotedName("push_{$this->suffix}")
                ->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setUnsigned(true)->setAutoincrement(true)->create(),
                    Column::editor()->setUnquotedName('subscribable_type')->setTypeName(Types::STRING)->setLength(255)->create(),
                    Column::editor()->setUnquotedName('subscribable_id')->setTypeName(Types::BIGINT)->setUnsigned(true)->create(),
                    Column::editor()->setUnquotedName('endpoint')->setTypeName(Types::STRING)->setLength(500)->create(),
                    $nullable('public_key'),
                    $nullable('auth_token'),
                    $nullable('content_encoding'),
                    ...$timestamps('created_at', 'updated_at'),
                )
                ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->setIndexes(
                    Index::editor()->setUnquotedName("push_{$this->suffix}_endpoint")->setType(IndexType::UNIQUE)->setUnquotedColumnNames('endpoint')->create(),
                    Index::editor()->setUnquotedName("push_{$this->suffix}_morph")->setUnquotedColumnNames('subscribable_type', 'subscribable_id')->create(),
                )
                ->create(),
        );
    }

    /** Runs a queue worker on a transport until it is empty. */
    protected function work(Application $application, string $transport): void
    {
        $tester = new CommandTester($application->get(QueueWorkCommand::class));
        $status = $tester->execute(['transports' => [$transport], '--sleep' => '0', '--stop-when-empty' => true]);
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
    }

    /** @return list<class-string> the events the recorder got, by class */
    protected function events(): array
    {
        return array_map(static fn(object $event): string => $event::class, $this->recorder->events);
    }
}
