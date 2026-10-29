<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Scheduler\Tests;

use ArrayObject;
use Closure;
use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Queue\QueueServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Scheduler\Console\ScheduleListCommand;
use Crystoline\Xpsr\Scheduler\Console\ScheduleRunCommand;
use Crystoline\Xpsr\Scheduler\Console\ScheduleWorkCommand;
use Crystoline\Xpsr\Scheduler\Schedule;
use Crystoline\Xpsr\Scheduler\SchedulerOptions;
use Crystoline\Xpsr\Scheduler\SchedulerServiceRegistry;
use Crystoline\Xpsr\Scheduler\Task;
use Crystoline\Xpsr\Scheduler\TaskRunner;
use Crystoline\Xpsr\Scheduler\Tests\Fixtures\EchoCommand;
use Crystoline\Xpsr\Scheduler\Tests\Fixtures\MemoryLogger;
use Crystoline\Xpsr\Scheduler\Tests\Fixtures\Recorder;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Task schedules, schedule:run with commands, callbacks, and queued jobs, conditions, locks,
 * time zones, schedule:list, and schedule:work. The clock starts on Thursday 29 October 2026,
 * 08:00 UTC.
 */
final class SchedulerTest extends TestCase
{
    private string $root;
    private Recorder $recorder;
    private MemoryLogger $logger;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-scheduler-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/routes');
        $this->recorder = new Recorder();
        $this->logger = new MemoryLogger();
        $this->clock = new MockClock('2026-10-29 08:00:00', 'UTC');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testScheduleRunRunsTheTasksThatAreDue(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            use Crystoline\Xpsr\Scheduler\Schedule;
            use Crystoline\Xpsr\Scheduler\Tests\Fixtures\Recorder;
            use Crystoline\Xpsr\Scheduler\Tests\Fixtures\RecordJob;

            return static function (Schedule $schedule): void {
                $schedule->command('test:echo hello')->daily('08:00');
                $schedule->call(static function (Recorder $recorder): void {
                    $recorder->done[] = 'callback';
                })->everyMinutes(5);
                $schedule->job(new RecordJob('hourly'))->hourly();
                $schedule->command('test:echo later')->daily('09:00');
                $schedule->command('test:echo friday')->daily('08:00')->on('friday');
            };
            PHP, queue: true);

        $tester = $this->runSchedule($application, OutputInterface::VERBOSITY_VERBOSE);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['command hello', 'callback', 'job hourly'], $this->recorder->done, 'In order; not the 09:00 or Friday ones.');
        self::assertMatchesRegularExpression(
            '/\ARan test:echo hello in \d+\.\d\ds\n    Echo: hello\nRan Closure at schedule\.php:8 in \d+\.\d\ds\nRan Crystoline\\\\Xpsr\\\\Scheduler\\\\Tests\\\\Fixtures\\\\RecordJob in \d+\.\d\ds\n\z/',
            $tester->getDisplay(),
            'With -v, a command\'s output is shown too.',
        );

        $this->clock->sleep(60);
        $tester = $this->runSchedule($application);
        self::assertSame("No tasks are due.\n", $tester->getDisplay());
    }

    public function testFailuresAreReportedAndTheOtherTasksStillRun(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            use Crystoline\Xpsr\Scheduler\Schedule;

            return static function (Schedule $schedule): void {
                $schedule->command('test:echo broken --fail');
                $schedule->call(static function (): void {
                    throw new RuntimeException('Disk full.');
                })->name('cleanup');
                $schedule->command('nothing:here');
                $schedule->command('test:echo after');
            };
            PHP);

        $tester = $this->runSchedule($application);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(['command broken', 'command after'], $this->recorder->done);
        self::assertMatchesRegularExpression('/\ARan test:echo after in \d+\.\d\ds\n\z/', $tester->getDisplay());
        $errors = $tester->getErrorOutput();
        self::assertStringContainsString("Failed test:echo broken --fail: exit code 3\n    Echo: broken\n", $errors, 'A failed command\'s output is shown.');
        self::assertStringContainsString("Failed cleanup: Disk full.\n", $errors);
        self::assertStringContainsString("Failed nothing:here: exit code 1\n", $errors);
        self::assertStringContainsString('"nothing"', $errors, 'Symfony\'s message for an unknown command.');

        self::assertCount(3, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame('Scheduled task {task} failed: {message}', $this->logger->records[0]['message']);
        self::assertSame(['task' => 'test:echo broken --fail', 'message' => 'exit code 3', 'output' => 'Echo: broken'], $this->logger->records[0]['context']);
        self::assertInstanceOf(RuntimeException::class, $this->logger->records[1]['context']['exception'] ?? null);
    }

    public function testConditionsDecideWhetherADueTaskRuns(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            use Crystoline\Xpsr\Scheduler\Schedule;
            use Crystoline\Xpsr\Scheduler\Tests\Fixtures\Recorder;

            return static function (Schedule $schedule): void {
                $schedule->call(static fn(Recorder $recorder) => $recorder->done[] = 'runs')->name('a')
                    ->when(static fn(Recorder $recorder): bool => $recorder->done === []);
                $schedule->call(static fn(Recorder $recorder) => $recorder->done[] = 'when false')->name('b')
                    ->when(static fn(): bool => false);
                $schedule->call(static fn(Recorder $recorder) => $recorder->done[] = 'skip true')->name('c')
                    ->skip(static fn(): bool => true);
                $schedule->call(static fn() => null)->name('d')->when(static fn(int $count): bool => true);
            };
            PHP);

        $tester = $this->runSchedule($application);
        self::assertSame(['runs'], $this->recorder->done);
        self::assertStringContainsString("Skipped b: its when() condition is false\nSkipped c: its skip() condition is true\n", $tester->getDisplay());
        self::assertStringContainsString('Failed d: The scheduled task "d" has a callback parameter $count that needs a class or interface type the container provides, or a default value.', $tester->getErrorOutput());
    }

    public function testWithoutOverlappingSkipsARunWhileThePreviousOneHoldsTheLock(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            return static function (Crystoline\Xpsr\Scheduler\Schedule $schedule): void {
                $schedule->command('test:echo locked')->withoutOverlapping();
            };
            PHP);
        $task = $application->get(Schedule::class)->tasks()[0];
        self::assertSame(86400, $task->lockSeconds());
        $held = (new LockFactory(new FlockStore($this->root . '/var/locks')))->createLock($task->lockKey());
        self::assertTrue($held->acquire(), 'The test holds the lock, as a running schedule:run would.');

        $tester = $this->runSchedule($application);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("Skipped test:echo locked: the previous run is still going\n", $tester->getDisplay());
        self::assertSame([], $this->recorder->done);

        $held->release();
        $this->runSchedule($application);
        $this->runSchedule($application);
        self::assertSame(['command locked', 'command locked'], $this->recorder->done, 'The task releases its lock when it ends.');
    }

    public function testTasksAreDueInTheirOwnTimeZone(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            return static function (Crystoline\Xpsr\Scheduler\Schedule $schedule): void {
                $schedule->command('test:echo lagos')->daily('09:00')->timezone('Africa/Lagos');
                $schedule->command('test:echo default')->daily('09:00');
            };
            PHP);

        $this->runSchedule($application);
        self::assertSame(['command lagos'], $this->recorder->done, '09:00 in Lagos is 08:00 UTC.');

        $application = $this->application(null, ['timezone' => 'Africa/Lagos']);
        self::assertSame('Africa/Lagos', $application->get(Schedule::class)->tasks()[1]->timezoneName(), 'scheduler.timezone is the default.');
        $this->recorder->done = [];
        $this->runSchedule($application);
        self::assertSame(['command lagos', 'command default'], $this->recorder->done);
    }

    public function testScheduleListShowsEachTaskAndItsNextRun(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            use Crystoline\Xpsr\Scheduler\Schedule;
            use Crystoline\Xpsr\Scheduler\Tests\Fixtures\RecordJob;

            return static function (Schedule $schedule): void {
                $schedule->command('test:echo hello')->daily('09:30');
                $schedule->call(static fn() => null, 'ping')->everyMinutes(15)->withoutOverlapping();
                $schedule->job(new RecordJob('weekly'))->weekly('monday', '06:00')->timezone('Africa/Lagos');
            };
            PHP);

        $tester = new CommandTester($application->get(ScheduleListCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\| Task +\| Schedule +\| Time zone +\| Next run +\|/', $display);
        self::assertMatchesRegularExpression('/\| test:echo hello +\| 30 9 \* \* \* +\| UTC +\| 2026-10-29 09:30 \|/', $display);
        self::assertMatchesRegularExpression('/\| ping \(without overlapping\) +\| \*\/15 \* \* \* \* +\| UTC +\| 2026-10-29 08:15 \|/', $display);
        self::assertMatchesRegularExpression('/\| Crystoline\\\\Xpsr\\\\Scheduler\\\\Tests\\\\Fixtures\\\\RecordJob +\| 0 6 \* \* 1 +\| Africa\/Lagos +\| 2026-11-02 06:00 \|/', $display);

        unlink($this->root . '/routes/schedule.php');
        $tester = new CommandTester($this->application(null)->get(ScheduleListCommand::class));
        $tester->execute([]);
        self::assertSame("No tasks are scheduled. Define them in routes/schedule.php, or the files in scheduler.files.\n", $tester->getDisplay(), 'A missing routes/schedule.php means no tasks.');
    }

    public function testQueuedJobsNeedTheQueueProvider(): void
    {
        $application = $this->application(<<<'PHP'
            <?php
            return static function (Crystoline\Xpsr\Scheduler\Schedule $schedule): void {
                $schedule->job(new Crystoline\Xpsr\Scheduler\Tests\Fixtures\RecordJob('x'));
            };
            PHP);

        $tester = $this->runSchedule($application);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Schedule::job() needs crystoline/xpsr-queue', $tester->getErrorOutput());
    }

    public function testScheduleFilesMustReturnAFunction(): void
    {
        $application = $this->application('<?php return 42;');
        $this->assertContainerFails($application, Schedule::class, LogicException::class, '/routes/schedule.php must return a function that takes the Schedule');

        $application = $this->application(null, ['files' => ['routes/missing.php']]);
        $this->assertContainerFails($application, Schedule::class, InvalidArgumentException::class, 'scheduler.files lists routes/missing.php, which does not exist.');

        $application = $this->application(null, ['lock' => 'carrier-pigeon']);
        $this->assertContainerFails($application, TaskRunner::class, InvalidArgumentException::class, 'scheduler.lock: ');
    }

    public function testScheduleWorkRunsScheduleRunEachMinute(): void
    {
        $application = $this->application(null);
        $tester = new CommandTester($application->get(ScheduleWorkCommand::class));
        self::assertSame(Command::FAILURE, $tester->execute(['--time-limit' => '60']));
        self::assertStringContainsString('xpsr does not exist.', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--time-limit' => 'soon']));

        file_put_contents($this->root . '/xpsr', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/runs.log', implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
            echo "No tasks are due.\n";
            PHP);
        $this->clock->sleep(30);
        self::assertSame(Command::SUCCESS, $tester->execute(['--time-limit' => '150']));
        self::assertSame("schedule:run\nschedule:run\nschedule:run\n", file_get_contents($this->root . '/runs.log'), 'At 08:00:30, 08:01, and 08:02.');
        self::assertSame(3, substr_count($tester->getDisplay(), 'No tasks are due.'));
        self::assertSame('2026-10-29 08:03:00', $this->clock->now()->format('Y-m-d H:i:s'));
    }

    /** @param Closure(Task): Task $frequency */
    #[DataProvider('frequencies')]
    public function testFrequencies(Closure $frequency, string $expression): void
    {
        self::assertSame($expression, $frequency((new Schedule())->command('test:echo x'))->expression());
    }

    /** @return iterable<string, array{Closure(Task): Task, string}> */
    public static function frequencies(): iterable
    {
        yield 'default' => [static fn(Task $task): Task => $task, '* * * * *'];
        yield 'every minute' => [static fn(Task $task): Task => $task->daily()->everyMinute(), '* * * * *'];
        yield 'every 5 minutes' => [static fn(Task $task): Task => $task->everyMinutes(5), '*/5 * * * *'];
        yield 'hourly' => [static fn(Task $task): Task => $task->hourly(15), '15 * * * *'];
        yield 'every 6 hours' => [static fn(Task $task): Task => $task->everyHours(6, 30), '30 */6 * * *'];
        yield 'daily' => [static fn(Task $task): Task => $task->daily('03:05'), '5 3 * * *'];
        yield 'weekly' => [static fn(Task $task): Task => $task->weekly('Monday', '08:30'), '30 8 * * 1'];
        yield 'weekly on 7' => [static fn(Task $task): Task => $task->weekly(7), '0 0 * * 0'];
        yield 'monthly' => [static fn(Task $task): Task => $task->monthly(15, '12:00'), '0 12 15 * *'];
        yield 'yearly' => [static fn(Task $task): Task => $task->yearly(7, 4, '09:00'), '0 9 4 7 *'];
        yield 'weekdays after' => [static fn(Task $task): Task => $task->daily('08:00')->weekdays(), '0 8 * * 1-5'];
        yield 'weekdays before' => [static fn(Task $task): Task => $task->weekdays()->daily('08:00'), '0 8 * * 1-5'];
        yield 'weekends' => [static fn(Task $task): Task => $task->hourly()->weekends(), '0 * * * 0,6'];
        yield 'on' => [static fn(Task $task): Task => $task->daily()->on('friday', 'monday', 7, 1), '0 0 * * 0,1,5'];
        yield 'cron' => [static fn(Task $task): Task => $task->cron('30 2 * * 1-5'), '30 2 * * 1-5'];
        yield 'cron alias' => [static fn(Task $task): Task => $task->cron('@daily'), '0 0 * * *'];
        yield 'cron then weekends' => [static fn(Task $task): Task => $task->cron('30 2 * * 1')->weekends(), '30 2 * * 0,6'];
        yield 'cron with both days' => [static fn(Task $task): Task => $task->cron('0 0 1 * 1'), '0 0 1 * 1'];
    }

    /** @param Closure(Task): mixed $frequency */
    #[DataProvider('invalidFrequencies')]
    public function testInvalidFrequenciesAreRejected(Closure $frequency, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $frequency((new Schedule())->command('test:echo x'));
    }

    /** @return iterable<string, array{Closure(Task): mixed, string}> */
    public static function invalidFrequencies(): iterable
    {
        yield 'uneven minutes' => [static fn(Task $task) => $task->everyMinutes(7), 'everyMinutes(7): the minutes must divide an hour evenly'];
        yield 'uneven hours' => [static fn(Task $task) => $task->everyHours(5), 'everyHours(5): the hours must divide a day evenly'];
        yield 'bad hour' => [static fn(Task $task) => $task->daily('24:00'), '"24:00" is not a time of day. Use 24-hour HH:MM'];
        yield 'not a time' => [static fn(Task $task) => $task->daily('8am'), '"8am" is not a time of day.'];
        yield 'bad minute' => [static fn(Task $task) => $task->hourly(60), '60 is not a minute: it must be from 0 to 59.'];
        yield 'bad day name' => [static fn(Task $task) => $task->weekly('funday'), '"funday" is not a day of the week.'];
        yield 'bad day number' => [static fn(Task $task) => $task->on(8), '8 is not a day of the week: it must be from 0 to 7.'];
        yield 'no days' => [static fn(Task $task) => $task->on(), 'on() needs at least one day.'];
        yield 'bad month day' => [static fn(Task $task) => $task->monthly(32), '32 is not a day of the month'];
        yield 'bad month' => [static fn(Task $task) => $task->yearly(13), '13 is not a month'];
        yield 'month day and weekdays' => [static fn(Task $task) => $task->monthly(15)->weekdays(), 'A task cannot be limited to both a day of the month and days of the week'];
        yield 'weekdays and month day' => [static fn(Task $task) => $task->weekdays()->monthly(15), 'A task cannot be limited to both a day of the month and days of the week'];
        yield 'not cron' => [static fn(Task $task) => $task->cron('sometimes'), '"sometimes" is not a cron expression.'];
        yield 'four fields' => [static fn(Task $task) => $task->cron('* * * *'), '"* * * *" is not a cron expression.'];
        yield 'bad time zone' => [static fn(Task $task) => $task->timezone('Mars/Olympus'), 'timezone() must be a time zone identifier'];
        yield 'bad period' => [static fn(Task $task) => $task->withoutOverlapping('a while'), 'withoutOverlapping() needs a period'];
        yield 'zero seconds' => [static fn(Task $task) => $task->withoutOverlapping(0), 'withoutOverlapping() needs a period'];
        yield 'empty name' => [static fn(Task $task) => $task->name(' '), 'A task name cannot be empty.'];
    }

    public function testDueTimesAndNextRuns(): void
    {
        $schedule = new Schedule('Africa/Lagos');
        $task = $schedule->command('test:echo x')->daily('09:00');
        self::assertTrue($task->isDue(new \DateTimeImmutable('2026-10-29 08:00:59 UTC')), 'Any second of the minute.');
        self::assertFalse($task->isDue(new \DateTimeImmutable('2026-10-29 09:00:00 UTC')));
        self::assertSame('2026-10-30 09:00 Africa/Lagos', $task->nextRun(new \DateTimeImmutable('2026-10-29 08:00:00 UTC'))->format('Y-m-d H:i e'));
        self::assertSame([$task], $schedule->due(new \DateTimeImmutable('2026-10-29 08:00:00 UTC')));
        self::assertSame([], $schedule->due(new \DateTimeImmutable('2026-10-29 08:01:00 UTC')));
        self::assertSame(300, $task->withoutOverlapping('5 minutes')->lockSeconds());
        self::assertSame(90, $task->withoutOverlapping(90)->lockSeconds());
        self::assertNotSame($task->lockKey(), $schedule->command('test:echo y')->daily('09:00')->lockKey());
    }

    public function testDescriptions(): void
    {
        $schedule = new Schedule();
        self::assertSame('test:echo x --flag', $schedule->command('  test:echo x --flag ')->description());
        self::assertSame('strtoupper', $schedule->call('strtoupper')->description());
        self::assertSame('ArrayObject::count', $schedule->call([new ArrayObject(), 'count'])->description());
        self::assertSame('Closure at SchedulerTest.php:' . __LINE__, $schedule->call(static fn() => null)->description());
        self::assertSame('nightly', $schedule->call(static fn() => null, 'nightly')->description());
        self::assertSame('renamed', $schedule->command('test:echo x')->name('renamed')->description());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A scheduled command needs a name');
        $schedule->command(' ');
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SchedulerOptions::fromArray($config, $this->root);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['file' => 'x.php'], 'Unknown option scheduler.file. Known options: files, timezone, lock.'];
        yield 'files not a list' => [['files' => 'routes/schedule.php'], 'scheduler.files must be a list of PHP files'];
        yield 'bad time zone' => [['timezone' => 'Nowhere'], 'scheduler.timezone must be a time zone identifier'];
        yield 'empty lock' => [['lock' => ''], 'scheduler.lock must be "flock" or a Symfony Lock store DSN'];
    }

    public function testOptionsHaveDefaults(): void
    {
        $options = SchedulerOptions::fromArray([], $this->root, 'Europe/Berlin');
        self::assertSame([], $options->files, 'routes/schedule.php does not exist yet.');
        self::assertSame('Europe/Berlin', $options->timezone, 'app.timezone');
        self::assertSame('flock', $options->lock);

        touch($this->root . '/routes/schedule.php');
        self::assertSame([$this->root . '/routes/schedule.php'], SchedulerOptions::fromArray([], $this->root)->files);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function application(?string $schedule, array $config = [], bool $queue = false): Application
    {
        if ($schedule !== null) {
            file_put_contents($this->root . '/routes/schedule.php', $schedule);
        }
        $application = new Application($this->root);
        $settings = $application->getConfig();
        $settings->set('app.registry', [
            ConsoleServiceRegistry::class,
            SchedulerServiceRegistry::class,
            ...($queue ? [QueueServiceRegistry::class] : []),
        ]);
        if ($config !== []) {
            $settings->set('scheduler', $config);
        }
        if ($queue) {
            $settings->set('queue', ['transports' => ['default' => ['dsn' => 'sync://']], 'failed' => false]);
        }
        $container = $application->getContainer();
        $container->instance(Recorder::class, $this->recorder);
        $container->instance(LoggerInterface::class, $this->logger);
        $container->instance(ClockInterface::class, $this->clock);
        $application->boot();
        $application->get(CommandRegistry::class)->register('test:echo', EchoCommand::class, 'Echo a word.');

        return $application;
    }

    private function runSchedule(Application $application, int $verbosity = OutputInterface::VERBOSITY_NORMAL): CommandTester
    {
        $tester = new CommandTester($application->get(ScheduleRunCommand::class));
        $tester->execute([], ['verbosity' => $verbosity, 'capture_stderr_separately' => true]);

        return $tester;
    }

    /** @param class-string<\Throwable> $type */
    private function assertContainerFails(Application $application, string $service, string $type, string $message): void
    {
        try {
            $application->get($service);
            self::fail("{$service} was created.");
        } catch (ContainerException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf($type, $previous);
            self::assertStringContainsString($message, $previous->getMessage());
        }
    }
}
