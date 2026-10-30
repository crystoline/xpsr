<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Lock\Console\MakeLockTableCommand;
use Crystoline\Xpsr\Lock\LockServiceRegistry;
use Crystoline\Xpsr\Log\Monolog\MonologServiceRegistry;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\Queue\Attribute\Unique;
use Crystoline\Xpsr\Queue\Console\MakeQueueTableCommand;
use Crystoline\Xpsr\Queue\Console\QueueRetryCommand;
use Crystoline\Xpsr\Queue\Console\QueueWorkCommand;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Queue\JobInterface;
use Crystoline\Xpsr\Queue\JobRunner;
use Crystoline\Xpsr\Queue\Messenger\Transports;
use Crystoline\Xpsr\Queue\Messenger\UniqueJobs;
use Crystoline\Xpsr\Queue\Queue;
use Crystoline\Xpsr\Queue\QueueInterface;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Queue\Testing\FakeQueue;
use Crystoline\Xpsr\Queue\Tests\Fixtures\RecordJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\Recorder;
use Crystoline\Xpsr\Queue\Tests\Fixtures\RequestIdJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\UniqueOnStartJob;
use Crystoline\Xpsr\Queue\Tests\Fixtures\UniqueWalletJob;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * #[Unique] jobs and request IDs. The jobs' locks are in memory, except where a worker in another
 * application, as another process would be, releases them: there the store is the database
 * (SQLite), and Redis when XPSR_TEST_REDIS_URLS is set.
 */
final class UniqueJobsTest extends TestCase
{
    private string $root;
    private string $suffix;
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-unique-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        $this->suffix = bin2hex(random_bytes(3));
        $this->recorder = new Recorder();

        $application = $this->boot();
        foreach ([MakeQueueTableCommand::class, MakeLockTableCommand::class] as $command) {
            $make = new CommandTester($application->get($command));
            self::assertSame(Command::SUCCESS, $make->execute([]), $make->getDisplay());
        }
        self::assertCount(2, $application->get(MigratorFactory::class)->create()->migrate());
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string, mixed> $lock */
    private function boot(array $lock = ['store' => 'in-memory'], bool $locks = true): Application
    {
        $application = new Application($this->root);
        $config = $application->getConfig();
        $config->set('app.registry', array_values(array_filter([
            ConsoleServiceRegistry::class,
            DatabaseServiceRegistry::class,
            $locks ? LockServiceRegistry::class : null,
            MonologServiceRegistry::class,
            QueueServiceRegistry::class,
        ])));
        $config->set('database', [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'pdo_sqlite', 'path' => 'var/queue.sqlite']],
            'migrations' => ['path' => 'migrations'],
        ]);
        $config->set('lock', $lock + ['prefix' => "test-{$this->suffix}"]);
        $config->set('logging', ['channel' => 'file', 'path' => 'var/log/app.log', 'format' => 'json']);
        $config->set('queue', ['transports' => [
            'default' => ['dsn' => 'sync://'],
            // Serialized, as on a real transport, so the lock's key makes the trip.
            'memory' => ['dsn' => 'in-memory://?serialize=true', 'retry' => ['max_retries' => 1, 'delay' => 0]],
            'db' => ['dsn' => 'database://main?queue_name=jobs', 'retry' => ['max_retries' => 0]],
        ]]);
        $application->getContainer()->instance(Recorder::class, $this->recorder);
        $application->boot();

        return $application;
    }

    public function testADuplicateIsDroppedUntilTheJobIsDone(): void
    {
        $application = $this->boot();
        $queue = $application->get(QueueInterface::class);
        $queue->dispatch(new UniqueWalletJob(1, 'first'), queue: 'memory');
        $queue->dispatch(new UniqueWalletJob(1, 'duplicate'), queue: 'memory');
        $queue->dispatch(new UniqueWalletJob(2, 'other wallet'), queue: 'memory');
        self::assertSame(2, $this->waiting($application, 'memory'), 'uniqueId() tells the wallets apart.');
        self::assertContains('Queued job ' . UniqueWalletJob::class . ' was not dispatched: an identical job is still queued or running.', array_column($this->log(), 'message'));

        $this->work($application, 'memory');
        self::assertSame(['first', 'other wallet'], $this->recorder->done);
        $queue->dispatch(new UniqueWalletJob(1, 'next'), queue: 'memory');
        self::assertSame(1, $this->waiting($application, 'memory'), 'Released when the job succeeded.');
    }

    public function testTheLockIsKeptForRetriesAndReleasedAfterTheLastFailure(): void
    {
        $application = $this->boot();
        $queue = $application->get(QueueInterface::class);
        $queue->dispatch(new UniqueWalletJob(1, 'attempt', fail: true), queue: 'memory');
        $this->work($application, 'memory', ['--limit' => '1']);
        self::assertSame(['attempt'], $this->recorder->done);

        $queue->dispatch(new UniqueWalletJob(1, 'duplicate'), queue: 'memory');
        self::assertSame(1, $this->waiting($application, 'memory'), 'Only the retry: the lock is kept between attempts.');

        $this->work($application, 'memory');
        self::assertSame(['attempt', 'attempt'], $this->recorder->done, 'One retry, then no more.');
        $queue->dispatch(new UniqueWalletJob(1, 'after'), queue: 'memory');
        self::assertSame(1, $this->waiting($application, 'memory'), 'Released after the last failure.');
    }

    public function testUntilProcessingReleasesTheLockWhenTheJobStarts(): void
    {
        $application = $this->boot();
        $queue = $application->get(QueueInterface::class);
        $queue->dispatch(new UniqueOnStartJob('first', again: true), queue: 'memory');
        $queue->dispatch(new UniqueOnStartJob('duplicate'), queue: 'memory');
        $this->work($application, 'memory');
        self::assertSame(['first', 'again'], $this->recorder->done, 'Released as it started, so it could queue a copy.');

        $queue->dispatch(new UniqueWalletJob(1, 'until done', again: true), queue: 'memory');
        $this->work($application, 'memory');
        self::assertSame(['first', 'again', 'until done'], $this->recorder->done, 'Held while it ran, so its copy was dropped.');
    }

    public function testOnSyncTheLockIsReleasedAfterTheJobRuns(): void
    {
        $queue = $this->boot()->get(QueueInterface::class);
        $queue->dispatch(new UniqueWalletJob(1, 'now'));
        $queue->dispatch(new UniqueWalletJob(1, 'again'));
        try {
            $queue->dispatch(new UniqueWalletJob(1, 'broken', fail: true));
            self::fail('The exception was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Wallet 1 is offline.', $exception->getMessage());
        }
        $queue->dispatch(new UniqueWalletJob(1, 'recovered'));
        self::assertSame(['now', 'again', 'broken', 'recovered'], $this->recorder->done);
    }

    public function testAJobThatIsNotSentReleasesItsLock(): void
    {
        $application = $this->boot();
        $down = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new RuntimeException('The queue is down.');
            }
        };
        $queue = new Queue($down, $application->get(Transports::class), 'memory', $application->get(UniqueJobs::class));
        try {
            $queue->dispatch(new UniqueWalletJob(1));
            self::fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('The queue is down.', $exception->getMessage());
        }
        $application->get(QueueInterface::class)->dispatch(new UniqueWalletJob(1, 'after the outage'), queue: 'memory');
        self::assertSame(1, $this->waiting($application, 'memory'));
    }

    /** @return iterable<string, array{array<string, mixed>}> lock options */
    public static function sharedStores(): iterable
    {
        yield 'database' => [['store' => 'database']];
        $url = trim(explode(',', (string) getenv('XPSR_TEST_REDIS_URLS'))[0]);
        if ($url !== '') {
            yield 'Redis' => [['store' => $url]];
        }
    }

    /** @param array<string, mixed> $lock */
    #[DataProvider('sharedStores')]
    public function testAWorkerInAnotherProcessReleasesTheLock(array $lock): void
    {
        $web = $this->boot($lock);
        $web->get(QueueInterface::class)->dispatch(new UniqueWalletJob(1, 'queued'), queue: 'db');
        $web->get(QueueInterface::class)->dispatch(new UniqueWalletJob(1, 'duplicate'), queue: 'db');
        self::assertSame(1, $this->waiting($web, 'db'));

        // Its own container, store, and connections, as a worker on another server has.
        $this->work($this->boot($lock), 'db');
        self::assertSame(['queued'], $this->recorder->done);
        $web->get(QueueInterface::class)->dispatch(new UniqueWalletJob(1, 'next'), queue: 'db');
        self::assertSame(1, $this->waiting($web, 'db'), 'The worker released the lock the web process took.');
    }

    public function testFileLocksCannotBeReleasedByTheWorker(): void
    {
        $queue = $this->boot(['store' => 'flock'])->get(QueueInterface::class);
        $queue->dispatch(new UniqueWalletJob(1, 'sync'));
        self::assertSame(['sync'], $this->recorder->done, 'On sync:// the job runs in this process, so file locks work.');
        foreach (['first', 'second'] as $attempt) {
            try {
                $queue->dispatch(new UniqueWalletJob(1, $attempt), queue: 'memory');
                self::fail('A file lock was sent to the worker.');
            } catch (LogicException $exception) {
                // The same error again, not a silent drop: the lock was released.
                self::assertSame(UniqueWalletJob::class . ' is #[Unique], and the worker releases its lock, but the lock store keeps each lock to the process that took it, as the flock store does. Set lock.store to a Redis DSN, redlock, or database.', $exception->getMessage());
            }
        }
    }

    public function testWithoutLocksUniqueJobsAreExplained(): void
    {
        $queue = $this->boot(locks: false)->get(QueueInterface::class);
        $queue->dispatch(new RecordJob('plain'));
        self::assertSame(['plain'], $this->recorder->done, 'Other jobs need no locks.');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(UniqueWalletJob::class . ' is #[Unique], which needs locks: run composer require crystoline/xpsr-lock, list Crystoline\Xpsr\Lock\LockServiceRegistry in app.registry, and set lock.store to a Redis DSN, redlock, or database.');
        $queue->dispatch(new UniqueWalletJob(1));
    }

    /** @return iterable<string, array{JobInterface, string}> */
    public static function invalidUniqueJobs(): iterable
    {
        yield 'unreadable for' => [new #[Unique(for: 'whenever')] class implements JobInterface {}, 'must be a number of seconds or a period such as "30 seconds".'];
        yield 'no time' => [new #[Unique(for: 0)] class implements JobInterface {}, 'must be at least one second.'];
        yield 'unknown until' => [new #[Unique(for: 60, until: 'later')] class implements JobInterface {}, 'must be "done" or "processing".'];
        yield 'array id' => [new #[Unique(for: 60)] class implements JobInterface {
            /** @return list<int> */
            public function uniqueId(): array
            {
                return [1];
            }
        }, '::uniqueId() must return a string or an integer.'];
    }

    #[DataProvider('invalidUniqueJobs')]
    public function testUniqueSettingsAreChecked(JobInterface $job, string $message): void
    {
        try {
            (new FakeQueue())->dispatch($job);
            self::fail('The settings were accepted.');
        } catch (InvalidArgumentException|LogicException $exception) {
            self::assertStringEndsWith($message, $exception->getMessage());
        }
    }

    public function testRetryKeepsAFailedJobWhileItsDuplicateIsQueued(): void
    {
        $application = $this->boot();
        $queue = $application->get(QueueInterface::class);
        $queue->dispatch(new UniqueWalletJob(1, 'attempt', fail: true), queue: 'db');
        $this->work($application, 'db');
        $failed = $application->get(FailedJobStore::class)->all();
        self::assertCount(1, $failed);

        $queue->dispatch(new UniqueWalletJob(1, 'queued since'), queue: 'db');
        $retry = new CommandTester($application->get(QueueRetryCommand::class));
        self::assertSame(Command::FAILURE, $retry->execute(['ids' => [(string) $failed[0]->id]]));
        self::assertStringContainsString(
            "Failed job {$failed[0]->id} (" . UniqueWalletJob::class . ') was not queued again: it is #[Unique], and an identical job is still queued or running. It stays in the failed jobs.',
            (string) preg_replace('/\s+/', ' ', $retry->getDisplay()),
        );
        self::assertCount(1, $application->get(FailedJobStore::class)->all());

        $this->work($application, 'db');
        self::assertSame(Command::SUCCESS, $retry->execute(['ids' => [(string) $failed[0]->id]]));
        self::assertSame([], $application->get(FailedJobStore::class)->all());
    }

    public function testFakeQueuesDropDuplicatesToo(): void
    {
        $application = $this->boot();
        $fake = new FakeQueue();
        $application->getContainer()->instance(QueueInterface::class, $fake);
        $fake->dispatch(new UniqueWalletJob(1, 'first'));
        $fake->dispatch(new UniqueWalletJob(1, 'duplicate'));
        $fake->dispatch(new UniqueWalletJob(2, 'other wallet', again: true));
        $fake->dispatch(new UniqueOnStartJob('on start', again: true));
        self::assertCount(3, $fake->dispatched());
        self::assertSame(['duplicate'], array_map(static fn(UniqueWalletJob $job): string => $job->label, $fake->dropped(UniqueWalletJob::class)));

        $fake->run($application->get(JobRunner::class));
        self::assertSame(['first', 'other wallet', 'on start', 'again'], $this->recorder->done);
        self::assertCount(2, $fake->dropped(), 'The wallet job\'s copy, dispatched while it ran.');
        $fake->dispatch(new UniqueWalletJob(1, 'next'));
        self::assertCount(1, $fake->dispatched(), 'Released once run.');
    }

    public function testJobsCarryTheRequestIdToTheWorker(): void
    {
        $application = $this->boot();
        $queue = $application->get(QueueInterface::class);
        $requestId = $application->get(RequestId::class);
        $requestId->set('req-7f3a');
        $queue->dispatch(new RequestIdJob(), queue: 'memory');
        $queue->dispatch(new RequestIdJob(fail: true), queue: 'db');
        $queue->dispatch(new RequestIdJob());
        self::assertSame(['req-7f3a'], $this->recorder->done, 'On sync:// the job runs in the request.');
        $requestId->set(null);
        $queue->dispatch(new RequestIdJob(), queue: 'memory');

        $this->work($application, 'memory');
        self::assertSame(['req-7f3a', 'req-7f3a', 'none'], $this->recorder->done, 'A job dispatched outside a request has no ID.');
        self::assertNull($requestId->get(), 'The worker\'s own ID comes back after each job.');

        $this->work($application, 'db');
        $failures = array_values(array_filter($this->log(), static fn(array $line): bool => str_contains((string) $line['message'], 'failed for the last time')));
        self::assertCount(1, $failures);
        self::assertSame('req-7f3a', $failures[0]['extra']['request_id'] ?? null, 'The failure is logged with the request\'s ID.');
    }

    /** @param array<string, mixed> $options */
    private function work(Application $application, string $transport, array $options = []): void
    {
        $tester = new CommandTester($application->get(QueueWorkCommand::class));
        $status = $tester->execute(['transports' => [$transport], '--sleep' => '0', '--stop-when-empty' => true] + $options);
        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
    }

    private function waiting(Application $application, string $transport): int
    {
        if ($transport === 'db') {
            return (int) $application->get(ConnectionManager::class)->connection()->fetchOne('SELECT COUNT(*) FROM queue_messages');
        }

        $memory = $application->get(Transports::class)->get($transport);
        self::assertInstanceOf(InMemoryTransport::class, $memory);

        return count($memory->getSent()) - count($memory->getAcknowledged()) - count($memory->getRejected());
    }

    /** @return list<array<string, mixed>> the log lines, decoded */
    private function log(): array
    {
        $file = $this->root . '/var/log/app.log';
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return array_map(static fn(string $line): array => (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines ?: []);
    }
}
