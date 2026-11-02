<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Console;

use Crystoline\Xpsr\Console\Commands\AbstractMakeCommand;
use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Creates a resource class in app/Http/Resource.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeResourceCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator->withStubDirectory(dirname(__DIR__) . '/Resources/stubs'), 'make:resource');
    }

    protected function kind(): string
    {
        return 'resource';
    }

    protected function subNamespace(): string
    {
        return 'Http\\Resource';
    }

    protected function example(): string
    {
        return 'PaymentResource';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: list its fields in toArray(), then return it from a route handler:',
            '    return new \\' . $target->className() . '($payment);',
            '    return \\' . $target->className() . '::collection($payments);',
        ];
    }
}
