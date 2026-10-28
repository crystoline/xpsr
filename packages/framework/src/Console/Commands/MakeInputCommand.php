<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Creates an input class for request validation. Registered by crystoline/xpsr-validation,
 * because the generated class uses Symfony Validator rules.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeInputCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator, 'make:input');
    }

    protected function kind(): string
    {
        return 'input';
    }

    protected function subNamespace(): string
    {
        return 'Http\Input';
    }

    protected function example(): string
    {
        return 'CreateTask';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: add a constructor parameter with rules for each field, then validate a request:',
            '    $input = $validator->validate($request, \\' . $target->className() . '::class);',
        ];
    }
}
