<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Console\Generator\GeneratedClass;
use Crystoline\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

final class MakeMiddlewareCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator, 'make:middleware');
    }

    protected function kind(): string
    {
        return 'middleware';
    }

    protected function subNamespace(): string
    {
        return 'Http\Middleware';
    }

    protected function example(): string
    {
        return 'EnsureJsonRequest';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: attach it to a route or group with ->middleware(\\' . $target->className() . '::class),',
            '      or add it to the "middleware" list in config/app.php to run it on every request.',
        ];
    }
}
