<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Console\Generator\GeneratedClass;
use Crystoline\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

final class MakeProviderCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator, 'make:provider');
    }

    protected function kind(): string
    {
        return 'provider';
    }

    protected function subNamespace(): string
    {
        return 'Registry';
    }

    protected function example(): string
    {
        return 'BillingServiceRegistry';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: add \\' . $target->className() . '::class to the "registry" list in config/app.php.',
        ];
    }
}
