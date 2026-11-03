<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests;

use Crystoline\Xpsr\Events\ListenerProvider;
use Crystoline\Xpsr\Health\Event\HealthChecked;
use Crystoline\Xpsr\Health\Scheduler\Heartbeat;
use Crystoline\Xpsr\Health\Status;
use Crystoline\Xpsr\Health\Tests\Fixtures\AnotherDiskCheck;
use Crystoline\Xpsr\Health\Tests\Fixtures\BrokenCheck;
use Crystoline\Xpsr\Health\Tests\Fixtures\FailingJob;
use Crystoline\Xpsr\Health\Tests\Fixtures\GatewayCheck;
use Crystoline\Xpsr\Health\Tests\Fixtures\Project;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use Crystoline\Xpsr\Scheduler\Console\ScheduleListCommand;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class HealthTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheReportCoversWhatIsInstalledInOrder(): void
    {
        $report = $this->project->report();
        self::assertSame(['database', 'cache', 'queue', 'workers', 'scheduler', 'locks', 'disk'], array_keys($report->results));
        self::assertSame(Status::Failing, $report->status(), 'No worker and no schedule:run have reported yet.');
        self::assertTrue($report->isFailing());

        $database = $report->result('database');
        self::assertSame(Status::Ok, $database?->status);
        self::assertSame('Connected', $database->message);
        self::assertArrayHasKey('milliseconds', $database->details['main']);
        self::assertGreaterThanOrEqual(0, $database->milliseconds);

        self::assertSame(Status::Ok, $report->result('cache')?->status);
        self::assertSame('Written and read back', $report->result('cache')->message);

        $queue = $report->result('queue');
        self::assertSame(Status::Ok, $queue?->status);
        self::assertSame(['default' => ['sync' => true], 'db' => ['waiting' => 0], 'failed' => 0], $queue->details);

        $workers = $report->result('workers');
        self::assertSame(Status::Failing, $workers?->status);
        self::assertSame('No worker has reported on "db"', $workers->message);
        self::assertSame(['db' => ['last_seen' => null]], $workers->details);

        $scheduler = $report->result('scheduler');
        self::assertSame(Status::Failing, $scheduler?->status);
        self::assertSame('schedule:run has not reported yet', $scheduler->message);

        self::assertSame(Status::Ok, $report->result('locks')?->status);
        $disk = $report->result('disk');
        self::assertSame(Status::Ok, $disk?->status);
        self::assertSame($this->project->root, $disk->details['path']);
        self::assertMatchesRegularExpression('/\A\d+\.\d% free \(/', $disk->message);

        $array = $report->toArray(false);
        self::assertSame('failing', $array['status']);
        self::assertSame('2026-11-03T10:00:00+00:00', $array['checked_at']);
        self::assertSame(['status' => 'ok'], $array['checks']['database'], 'Statuses alone without details.');
        self::assertSame(['status', 'message', 'details', 'milliseconds'], array_keys($report->toArray(true)['checks']['database']));
    }

    public function testHeartbeatsSatisfyTheWorkerAndSchedulerChecksUntilTheyAreStale(): void
    {
        $this->project->heartbeats();
        self::assertIsInt(Heartbeat::lastSeen($this->project->cache()), 'schedule:run ran the heartbeat task.');
        $list = new CommandTester($this->project->application->get(ScheduleListCommand::class));
        $list->execute([]);
        self::assertStringContainsString('health:heartbeat', $list->getDisplay());

        $report = $this->project->report();
        self::assertSame(Status::Ok, $report->status());
        self::assertSame('The worker is reporting', $report->result('workers')?->message);
        self::assertSame(['last_seen' => '2026-11-03T10:00:00+00:00', 'seconds_ago' => 0], $report->result('workers')->details['db']);
        self::assertSame('schedule:run reported 0 seconds ago', $report->result('scheduler')?->message);

        $this->project->clock->modify('+10 minutes');
        $report = $this->project->report();
        self::assertSame(Status::Failing, $report->status());
        self::assertSame('The worker on "db" was last seen 600 seconds ago', $report->result('workers')?->message);
        self::assertSame('schedule:run last reported 600 seconds ago', $report->result('scheduler')?->message);
    }

    public function testStaleLimitsAreConfigurable(): void
    {
        $this->project->remove();
        $this->project = new Project(['worker_stale' => 3600, 'schedule_stale' => '2 hours']);
        $this->project->boot();
        $this->project->heartbeats();
        $this->project->clock->modify('+10 minutes');
        $report = $this->project->report();
        self::assertSame(Status::Ok, $report->result('workers')?->status);
        self::assertSame(Status::Ok, $report->result('scheduler')?->status);
        self::assertSame(600, $report->result('scheduler')->details['seconds_ago']);
    }

    public function testFailedJobsAboveTheThresholdWarn(): void
    {
        $this->project->remove();
        $this->project = new Project(['skip' => ['workers', 'scheduler']]);
        $this->project->boot();
        self::assertSame(Status::Ok, $this->project->report()->status());

        $this->project->application->get(FailedJobStore::class)->add('db', new FailingJob(), new RuntimeException('Broken.'));
        $report = $this->project->report();
        self::assertSame(Status::Warning, $report->status(), 'A warning does not fail the application.');
        self::assertSame('1 failed job', $report->result('queue')?->message);
        self::assertSame(1, $report->result('queue')->details['failed']);
        $this->project->remove();

        $this->project = new Project(['skip' => ['workers', 'scheduler'], 'failed_jobs' => 1]);
        $this->project->boot();
        $this->project->application->get(FailedJobStore::class)->add('db', new FailingJob(), new RuntimeException('Broken.'));
        self::assertSame(Status::Ok, $this->project->report()->result('queue')?->status);
    }

    public function testReportsAreCachedForAFewSecondsAndTheEventFiresOnEachRun(): void
    {
        $this->project->remove();
        $this->project = new Project(['skip' => ['workers', 'scheduler']]);
        $this->project->boot();
        $events = [];
        $this->project->application->get(ListenerProvider::class)->listen(HealthChecked::class, static function (HealthChecked $event) use (&$events): void {
            $events[] = $event->report->status()->value;
        });
        $health = $this->project->health();
        $first = $health->check();
        self::assertEquals($first->toArray(true), $health->check()->toArray(true), 'The same report for five seconds.');
        self::assertSame(['ok'], $events, 'The checks ran once.');
        $this->project->application->get(FailedJobStore::class)->add('db', new FailingJob(), new RuntimeException('Broken.'));
        self::assertSame(Status::Ok, $health->check()->status(), 'Still the cached report.');
        self::assertSame(Status::Warning, $health->check(fresh: true)->status());
        self::assertSame(Status::Warning, $health->check()->status(), 'The fresh run replaced the cached report.');
        self::assertSame(['ok', 'warning'], $events, 'Once per run, not per cached answer.');
        $this->project->remove();

        $this->project = new Project(['skip' => ['workers', 'scheduler'], 'cache' => 0]);
        $this->project->boot();
        $runs = 0;
        $this->project->application->get(ListenerProvider::class)->listen(HealthChecked::class, static function () use (&$runs): void {
            ++$runs;
        });
        $health = $this->project->health();
        $health->check();
        $health->check();
        self::assertSame(2, $runs, 'Not cached.');
    }

    public function testABrokenConnectionFailsTheDatabaseCheckUnlessLeftOut(): void
    {
        $connections = [
            'main' => ['driver' => 'pdo_sqlite', 'path' => 'var/app.sqlite'],
            'reports' => ['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'nobody', 'password' => 'nothing', 'dbname' => 'nothing'],
        ];
        $this->project->remove();
        $this->project = new Project([], $connections);
        $this->project->boot();
        $database = $this->project->report()->result('database');
        self::assertSame(Status::Failing, $database?->status);
        self::assertStringStartsWith('Connection "reports": ', $database->message);
        self::assertArrayHasKey('milliseconds', $database->details['main']);
        self::assertArrayHasKey('error', $database->details['reports']);
        $this->project->remove();

        $this->project = new Project(['connections' => ['main']], $connections);
        $this->project->boot();
        self::assertSame(Status::Ok, $this->project->report()->result('database')?->status);
    }

    public function testYourOwnChecksJoinAndSkipsLeaveOut(): void
    {
        $this->project->remove();
        $this->project = new Project(['checks' => [GatewayCheck::class, BrokenCheck::class], 'skip' => ['disk', 'locks', 'workers', 'scheduler']]);
        $this->project->boot();
        $report = $this->project->report();
        self::assertSame(['database', 'cache', 'queue', 'gateway', 'broken'], array_keys($report->results));
        self::assertSame(Status::Warning, $report->result('gateway')?->status);
        self::assertSame(['balance' => 12], $report->result('gateway')->details);
        $broken = $report->result('broken');
        self::assertSame(Status::Failing, $broken?->status, 'A check that throws fails.');
        self::assertSame('Boom.', $broken->message);
        self::assertSame(['exception' => RuntimeException::class], $broken->details);
        self::assertSame(Status::Failing, $report->status());
    }

    public function testChecksMustBeChecksWithDistinctNames(): void
    {
        $this->project->remove();
        $this->project = new Project(['checks' => [\stdClass::class]]);
        $this->project->boot();
        try {
            $this->project->report();
            self::fail('Not refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Health check stdClass must implement Crystoline\Xpsr\Health\CheckInterface.', $exception->getMessage());
        }
        $this->project->remove();

        $this->project = new Project(['checks' => [AnotherDiskCheck::class]]);
        $this->project->boot();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Two health checks are named "disk".');
        $this->project->report();
    }

    public function testWithoutTransportsToWorkOnTheWorkersCheckIsFine(): void
    {
        $this->project->remove();
        $this->project = new Project(databaseTransport: false);
        $this->project->boot();
        $workers = $this->project->report()->result('workers');
        self::assertSame(Status::Ok, $workers?->status);
        self::assertSame('No transport needs a worker', $workers->message);
        self::assertSame(['default' => ['sync' => true], 'failed' => 0], $this->project->report()->result('queue')?->details);
    }

    public function testInvalidOptionsFailWhenHealthIsFirstUsed(): void
    {
        $this->project->remove();
        $this->project = new Project(['cache' => -1]);
        $this->project->boot();
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('health.cache must be a whole number of seconds, 0 or more.');
        $this->project->health();
    }
}
