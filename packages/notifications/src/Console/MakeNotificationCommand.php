<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Console;

use Crystoline\Xpsr\Console\Commands\AbstractMakeCommand;
use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Creates a notification class in app/Notification.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeNotificationCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator->withStubDirectory(dirname(__DIR__) . '/Resources/stubs'), 'make:notification');
    }

    protected function kind(): string
    {
        return 'notification';
    }

    protected function subNamespace(): string
    {
        return 'Notification';
    }

    protected function example(): string
    {
        return 'PaymentReceived';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: give it its data, and a method for each channel it goes to, then send it:',
            '    $notifier->send($user, new \\' . $target->className() . '(...));',
        ];
    }
}
