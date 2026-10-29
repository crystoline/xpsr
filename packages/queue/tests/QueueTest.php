<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Queue\Console\MakeJobCommand;
use Crystoline\Xpsr\Queue\Console\MakeQueueTableCommand;
use Crystoline\Xpsr\Queue\Console\QueueFailedCommand;
use Crystoline\Xpsr\Queue\Console\QueueForgetCommand;
use Crystoline\Xpsr\Queue\Console\QueueRestartCommand;
use Crystoline\Xpsr\Queue\Console\QueueRetryCommand;
use Crystoline\Xpsr\Queue\Console\QueueWorkCommand;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\JobRunner;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Queue\QueueOptions;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Queue\Testing\FakeQueue;
use Crystoline\Xpsr\Queue\Tests\Fixtures\ChainJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\FailingJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\MemoryJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\NeedsScalarJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\RecordJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\Recorder;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Jobs on the sync, in-memory, and database transports, the worker, retries, and the failed jobs
 * table. The database is the server in XPSR_TEST_PGSQL_URL or XPSR_TEST_MYSQL_URL when set,
 * otherwise SQLite.
 */
final class QueueTest extends TestCase
{
    private string $root;
    private string $suffix;
    private Application $application;
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-queue-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/app');
        $this->suffix = bin2hex(random_bytes(3));
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $this->application = new Application($this->root);
        $config = $this->application->getConfig();
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, QueueServiceRegistry::class]);
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/queue.sqlite']],
            'migrations' => ['path' => 'migrations', 'table' => "migrations_{$this->suffix}"],
        ]);
        $config->set('queue', [
            'transports' => [
                'default' => ['dsn' => 'sync://'],
                'memory' => ['dsn' => 'in-memory://'],
                'db' => ['dsn' => 'database://main?queue_name=jobs', 'retry' => ['max_retries' => 1, 'delay' => 0]],
            ],
            'table' => "queue_{$this->suffix}",
            'failed' => ['table' => "failed_{$this->suffix}"],
        ]);
        $this->recorder = new Recorder();
        $this->application->getContainer()->instance(Recorder::class, $this->recorder);
        $this->application->boot();

        $make = new CommandTester($this->application->get(MakeQueueTableCommand::class));
        self::assertSame(Command::SUCCESS, $make->execute([]), $make->getDisplay());
        self::assertCount(1, $this->application->get(MigratorFactory::class)->create()->migrate());
    }

    protected function tearDown(): void
    {
        $this->application->get(MigratorFactory::class)->create()->rollback();
        $schema = $this->db()->createSchemaManager();
        self::assertFalse($schema->tableExists("queue_{$this->suffix}"));
        $schema->dropTable("migrations_{$this->suffix}");
        $this->db()->close();
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheSyncTransportRunsJobsAtOnceWithInjectedServices(): void
    {
        $this->queue()->dispatch(new RecordJob('now'));
        self::assertSame(['now'], $this->recorder->done, 'Services by type; the default for the plain parameter.');

        try {
            $this->queue()->dispatch(new FailingJob('Sync failure.'));
            self::fail('The exception was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Sync failure.', $exception->getMessage(), 'The job\'s own exception, not a Messenger wrapper.');
        }
    }

    public function testJobsGoToTheirTransportAndTheWorkerRunsThem(): void
    {
        $this->queue()->dispatch(new MemoryJob());
        $this->queue()->dispatch(new RecordJob('in memory'), queue: 'memory');
        self::assertSame([], $this->recorder->done, '#[OnQueue] and queue: send them to the in-memory transport.');

        $worker = $this->work(['transports' => ['memory'], '--stop-when-empty' => true]);
        self::assertSame(['memory', 'in memory'], $this->recorder->done);
        self::assertStringContainsString('Done Crystoline\Xpsr\Queue\Tests\Fixtures\MemoryJob (memory)', $worker);
    }

    public function testTheDatabaseTransportKeepsJobsInItsTable(): void
    {
        $this->queue()->dispatch(new RecordJob('stored'), queue: 'db');
        $this->queue()->dispatch(new RecordJob('later'), delay: '10 minutes', queue: 'db');
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM queue_{$this->suffix} WHERE queue_name = 'jobs'"));

        $this->work(['transports' => ['db'], '--stop-when-empty' => true]);
        self::assertSame(['stored'], $this->recorder->done, 'The delayed job is not due yet.');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM queue_{$this->suffix}"));
    }

    public function testFailedJobsAreRetriedThenKeptForRetryOrForgetting(): void
    {
        $this->queue()->dispatch(new FailingJob('Out of luck.'), queue: 'db');
        $worker = $this->work(['transports' => ['db'], '--stop-when-empty' => true]);
        self::assertSame(['attempt', 'attempt'], $this->recorder->done, 'One retry, then no more.');
        self::assertStringContainsString('Out of luck. Retrying later.', $worker);
        self::assertStringContainsString('Out of luck. No retries left.', $worker);

        $failed = $this->application->get(FailedJobStore::class)->all();
        self::assertCount(1, $failed);
        self::assertSame(['db', FailingJob::class], [$failed[0]->transport, $failed[0]->jobClass]);
        self::assertStringStartsWith("RuntimeException: Out of luck.\n", $failed[0]->error);
        self::assertInstanceOf(FailingJob::class, $failed[0]->job);

        $list = new CommandTester($this->application->get(QueueFailedCommand::class));
        $list->execute(['--json' => true]);
        self::assertSame([[
            'id' => $failed[0]->id, 'job' => FailingJob::class, 'transport' => 'db', 'failed_at' => $failed[0]->failedAt->format(DATE_ATOM), 'error' => 'RuntimeException: Out of luck.',
        ]], json_decode($list->getDisplay(), true));

        $retry = new CommandTester($this->application->get(QueueRetryCommand::class));
        self::assertSame(Command::SUCCESS, $retry->execute(['ids' => [(string) $failed[0]->id]]));
        self::assertSame([], $this->application->get(FailedJobStore::class)->all());
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM queue_{$this->suffix}"), 'Queued again on its transport.');

        $this->work(['transports' => ['db'], '--stop-when-empty' => true]);
        $forget = new CommandTester($this->application->get(QueueForgetCommand::class));
        self::assertSame(Command::SUCCESS, $forget->execute(['--all' => true]));
        self::assertSame("Forgot 1 failed job(s).\n", $forget->getDisplay());
        self::assertSame(Command::FAILURE, $forget->execute(['ids' => ['999']]));
        self::assertSame(Command::INVALID, $retry->execute([]));
    }

    public function testTheWorkerStopsAtItsLimitsAndRefusesSync(): void
    {
        foreach (['one', 'two', 'three'] as $label) {
            $this->queue()->dispatch(new RecordJob($label), queue: 'memory');
        }
        $this->work(['transports' => ['memory'], '--limit' => '2']);
        self::assertSame(['one', 'two'], $this->recorder->done);

        $sync = new CommandTester($this->application->get(QueueWorkCommand::class));
        self::assertSame(Command::INVALID, $sync->execute([]));
        self::assertStringContainsString('The transport "default" is sync://', $sync->getDisplay());
        self::assertSame(Command::INVALID, $sync->execute(['transports' => ['redis']]));
        self::assertSame(Command::INVALID, $sync->execute(['transports' => ['memory'], '--memory-limit' => 'lots']));
    }

    public function testJobsCanQueueMoreJobs(): void
    {
        $this->queue()->dispatch(new ChainJob());
        self::assertSame(['chained'], $this->recorder->done);
    }

    public function testFakeQueuesKeepJobsForTests(): void
    {
        $fake = new FakeQueue();
        $fake->dispatch(new RecordJob('a'), delay: 60);
        $fake->dispatch(new MemoryJob(), queue: 'memory');
        self::assertCount(1, $fake->dispatched(RecordJob::class));
        self::assertSame([60, 'memory'], [$fake->all()[0]['delay'], $fake->all()[1]['queue']]);
        $fake->run($this->application->get(JobRunner::class));
        self::assertSame(['a', 'memory'], $this->recorder->done);
        self::assertSame([], $fake->dispatched());
    }

    public function testHandleParametersNeedServicesOrDefaults(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NeedsScalarJob::handle() parameter $count needs a class or interface type the container provides, or a default value.');
        $this->application->get(JobRunner::class)->run(new NeedsScalarJob());
    }

    public function testUnknownTransportsAndBridgesAreExplained(): void
    {
        try {
            $this->queue()->dispatch(new RecordJob('x'), queue: 'emails');
            self::fail('An unknown transport was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('There is no transport "emails" in queue.transports. Configured transports: default, memory, db.', $exception->getMessage());
        }
        $this->application->getConfig()->set('queue.transports.redis', ['dsn' => 'redis://localhost:6379/jobs']);
        $app = new Application($this->root);
        $app->getConfig()->set('queue', ['transports' => ['default' => ['dsn' => 'redis://localhost/jobs']]]);
        $app->getConfig()->set('app.registry', [QueueServiceRegistry::class]);
        $app->boot();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The redis:// transport needs symfony/redis-messenger: run composer require symfony/redis-messenger.');
        $app->get(QueueInterface::class)->dispatch(new RecordJob('x'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['driver' => 'redis'], 'Unknown option queue.driver.'];
        yield 'transport without dsn' => [['transports' => ['default' => []]], 'queue.transports.default must have a "dsn"'];
        yield 'unknown default' => [['transports' => ['a' => ['dsn' => 'sync://']], 'default' => 'b'], 'queue.default must name a transport in queue.transports: a.'];
        yield 'negative retries' => [['retry' => ['max_retries' => -1]], 'queue.retry.max_retries must be 0 or more'];
        yield 'bad delay' => [['retry' => ['delay' => 'soonish']], 'queue.retry.delay must be a number of seconds or a period'];
        yield 'bad failed' => [['failed' => 'yes'], 'queue.failed must be an array (table, connection), or false'];
        yield 'bad table' => [['table' => 'jobs; DROP'], 'queue.table must be a plain table name'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreExplained(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        QueueOptions::fromArray($config);
    }

    public function testTheCommandsAreRegisteredAndMakeJobWritesAJob(): void
    {
        foreach (['queue:work', 'queue:failed', 'queue:retry', 'queue:forget', 'queue:restart', 'make:job', 'make:queue-table'] as $name) {
            self::assertArrayHasKey($name, $this->application->get(CommandRegistry::class)->all());
        }
        $make = new CommandTester($this->application->get(MakeJobCommand::class));
        self::assertSame(Command::SUCCESS, $make->execute(['name' => 'SendWelcomeEmail']), $make->getDisplay());
        $file = (string) file_get_contents($this->root . '/app/Job/SendWelcomeEmail.php');
        self::assertStringContainsString('final class SendWelcomeEmail implements JobInterface', $file);
        self::assertStringContainsString('public function handle(LoggerInterface $logger): void', $file);
    }

    public function testRestartTellsWorkersThroughTheCache(): void
    {
        self::assertSame(Command::FAILURE, (new CommandTester(new QueueRestartCommand()))->execute([]));
        $cache = new ArrayAdapter();
        $restart = new CommandTester(new QueueRestartCommand($cache));
        self::assertSame(Command::SUCCESS, $restart->execute([]));
        self::assertIsFloat($cache->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->get());
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
    }

    private function queue(): QueueInterface
    {
        return $this->application->get(QueueInterface::class);
    }

    private function db(): Connection
    {
        return $this->application->get(ConnectionManager::class)->connection();
    }

    /** @param array<string, mixed> $input */
    private function work(array $input): string
    {
        $tester = new CommandTester($this->application->get(QueueWorkCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute($input + ['--sleep' => '0']), $tester->getDisplay());

        return $tester->getDisplay();
    }
}
