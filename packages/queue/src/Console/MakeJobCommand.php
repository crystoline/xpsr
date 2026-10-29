<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Crystoline\Xpsr\Console\Commands\AbstractMakeCommand;
use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Creates a job class in app/Job.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeJobCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator->withStubDirectory(dirname(__DIR__) . '/Resources/stubs'), 'make:job');
    }

    protected function kind(): string
    {
        return 'job';
    }

    protected function subNamespace(): string
    {
        return 'Job';
    }

    protected function example(): string
    {
        return 'SendWelcomeEmail';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: put the job\'s data in the constructor and the work in handle(), then dispatch it:',
            '    $queue->dispatch(new \\' . $target->className() . '(...));',
        ];
    }
}
