<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Console;

use Crystoline\Xpsr\Console\Event\CommandFinished;
use Crystoline\Xpsr\Console\Event\CommandStarting;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Span;
use Crystoline\Xpsr\Telemetry\Tracer;

/**
 * A span per console command, "command migrate", failed when the command throws or exits with
 * a code other than 0. Commands that run for as long as the process, queue:work, schedule:work,
 * and serve, get no span: their jobs and tasks have their own traces.
 *
 * @internal Listens for CommandStarting and CommandFinished when the commands instrumentation is on.
 */
final class TraceCommands
{
    public const LONG_RUNNING = ['queue:work', 'schedule:work', 'serve'];

    /** @var list<Span|null> one per command in progress, null for a long-running one */
    private array $spans = [];

    public function __construct(private readonly Tracer $tracer) {}

    public function starting(CommandStarting $event): void
    {
        if (in_array($event->command, self::LONG_RUNNING, true)) {
            $this->spans[] = null;

            return;
        }
        $this->spans[] = $this->tracer->start('command ' . $event->command, [Attributes::XPSR_COMMAND => $event->command]);
    }

    public function finished(CommandFinished $event): void
    {
        $span = array_pop($this->spans);
        if ($span === null) {
            return;
        }
        $span->setAttribute(Attributes::XPSR_COMMAND_EXIT_CODE, $event->exitCode);
        if ($event->exception !== null) {
            $span->recordException($event->exception);
        } elseif ($event->exitCode !== 0) {
            $span->setError("exit code {$event->exitCode}");
        }
        $span->end();
    }
}
