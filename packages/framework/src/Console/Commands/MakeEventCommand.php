<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Console\Generator\GeneratedClass;
use Crystoline\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MakeEventCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator, 'make:event');
    }

    protected function kind(): string
    {
        return 'event';
    }

    protected function subNamespace(): string
    {
        return 'Events';
    }

    protected function example(): string
    {
        return 'UserRegistered';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: dispatch it with an injected Psr\EventDispatcher\EventDispatcherInterface:',
            '    $this->events->dispatch(new \\' . $target->className() . '());',
            "      and create a listener: php xpsr make:listener SomeListener --event={$target->shortName}",
        ];
    }
}
