<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Tests;

use Crystoline\Xpsr\Scheduler\Console\ScheduleRunCommand;
use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Project;
use Crystoline\Xpsr\Telemetry\Testing\FinishedSpan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandAndTaskTracingTest extends TestCase
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

    public function testCommandsAreSpansWithTheirExitCodeOrException(): void
    {
        $spans = $this->project->spans();
        self::assertSame(0, $this->console(['command' => 'test:echo', 'word' => 'hi']));
        $ok = $spans->assertSpan('command test:echo');
        self::assertTrue($ok->isRoot());
        self::assertSame('test:echo', $ok->attribute('xpsr.command'));
        self::assertSame(0, $ok->attribute('xpsr.command.exit_code'));
        self::assertFalse($ok->isError());
        $spans->clear();

        self::assertSame(3, $this->console(['command' => 'test:echo', 'word' => 'bad', '--fail' => true]));
        $failed = $spans->assertSpan('command test:echo');
        self::assertTrue($failed->isError());
        self::assertSame('exit code 3', $failed->error());
        self::assertSame(3, $failed->attribute('xpsr.command.exit_code'));
        $spans->clear();

        self::assertSame(1, $this->console(['command' => 'test:echo', 'word' => 'boom', '--throw' => true]));
        $thrown = $spans->assertSpan('command test:echo');
        self::assertSame(\RuntimeException::class, $thrown->exception());
        self::assertSame('Command broke.', $thrown->error());
        self::assertSame(1, $thrown->attribute('xpsr.command.exit_code'));
        $spans->clear();

        self::assertSame(0, $this->console(['command' => 'list']));
        $spans->assertSpan('command list');
        self::assertSame(['command hi', 'command bad', 'command boom'], $this->project->recorder->done);
    }

    public function testScheduledTasksAreSpansAroundTheirCommandsAndCallbacks(): void
    {
        file_put_contents($this->project->root . '/routes/schedule.php', <<<'PHP'
            <?php
            use Crystoline\Xpsr\Scheduler\Schedule;
            use Crystoline\Xpsr\Telemetry\Tests\Fixtures\Recorder;

            return static function (Schedule $schedule): void {
                $schedule->command('test:echo nightly')->everyMinute();
                $schedule->call(static function (Recorder $recorder): void {
                    $recorder->done[] = 'cleanup';
                    throw new RuntimeException('Disk full.');
                })->name('cleanup')->everyMinute();
                $schedule->call(static fn(): null => null)->name('later')->daily('23:00');
            };
            PHP);
        $tester = new CommandTester($this->project->application->get(ScheduleRunCommand::class));
        $tester->execute([], ['capture_stderr_separately' => true]);
        self::assertSame(['command nightly', 'cleanup'], $this->project->recorder->done);

        $spans = $this->project->spans();
        $task = $spans->assertSpan('task test:echo nightly');
        self::assertSame('command', $task->attribute('xpsr.task.kind'));
        self::assertSame('done', $task->attribute('xpsr.task.status'));
        self::assertFalse($task->isError());
        $command = $spans->assertSpan('command test:echo');
        self::assertSame($task->spanId(), $command->parentSpanId(), 'The command runs inside its task.');

        $cleanup = $spans->assertSpan('task cleanup');
        self::assertSame('call', $cleanup->attribute('xpsr.task.kind'));
        self::assertSame('failed', $cleanup->attribute('xpsr.task.status'));
        self::assertTrue($cleanup->isError());
        self::assertSame(\RuntimeException::class, $cleanup->exception());
        $spans->assertNoSpan('task later', null);
        self::assertSame([], $spans->find('task later'), 'A task that is not due gets no span.');
        self::assertCount(0, array_filter($spans->spans(), static fn(FinishedSpan $span): bool => $span->name() === 'command schedule:run'), 'Run through the tester, not the console.');
    }

    /** @param array<string, mixed> $arguments */
    private function console(array $arguments): int
    {
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        return $this->project->application->getConsoleKernel()->run($input, new BufferedOutput());
    }
}
