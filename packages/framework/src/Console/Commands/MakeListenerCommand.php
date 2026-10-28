<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Console\Generator\GeneratedClass;
use Crystoline\Console\Generator\StubGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MakeListenerCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator, 'make:listener');
    }

    protected function configure(): void
    {
        parent::configure();
        $namespace = $this->generator->getNamespace() . '\\Events';
        $this->addOption('event', null, InputOption::VALUE_REQUIRED, "The event it handles: a class relative to {$namespace}, or a fully qualified name starting with \\.");
    }

    protected function kind(): string
    {
        return 'listener';
    }

    protected function subNamespace(): string
    {
        return 'Listeners';
    }

    protected function example(): string
    {
        return 'SendWelcomeEmail';
    }

    protected function replacements(GeneratedClass $target, InputInterface $input, SymfonyStyle $io): array
    {
        $event = $input->getOption('event');
        if ($event === null) {
            return ['event_type' => 'object', 'event_class' => ''];
        }
        if (!is_string($event)) {
            throw new InvalidArgumentException('--event must be a class name.');
        }
        if (str_starts_with($event, '\\')) {
            $class = substr($event, 1);
            if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $class)) {
                throw new InvalidArgumentException("--event \"{$event}\" is not a valid class name.");
            }
        } else {
            try {
                $class = $this->generator->resolve('Events', $event)->className();
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('--event: ' . $exception->getMessage(), 0, $exception);
            }
        }
        if (!class_exists($class) && !interface_exists($class)) {
            $io->getErrorStyle()->warning("{$class} does not exist yet. Create it with: php xpsr make:event");
        }

        return ['event_type' => '\\' . $class, 'event_class' => $class];
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        $event = $replacements['event_class'] === '' ? 'App\Events\SomeEvent' : $replacements['event_class'];

        return [
            'Next: list it in config/events.php under "listeners":',
            '    \\' . $event . '::class => [\\' . $target->className() . '::class],',
        ];
    }
}
