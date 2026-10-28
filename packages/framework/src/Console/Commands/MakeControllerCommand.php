<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MakeControllerCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator, 'make:controller');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('invokable', null, InputOption::VALUE_NONE, 'Create a single-action controller with __invoke().');
    }

    protected function kind(): string
    {
        return 'controller';
    }

    protected function subNamespace(): string
    {
        return 'Http\Controller';
    }

    protected function example(): string
    {
        return 'Admin/UserController';
    }

    protected function stub(InputInterface $input): string
    {
        return $input->getOption('invokable') ? 'controller.invokable' : 'controller';
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        $class = '\\' . $target->className() . '::class';
        $handler = $input->getOption('invokable') ? $class : "[{$class}, 'index']";

        return [
            'Next: add a route, for example in routes/web.php:',
            "    \$routes->get('/path', {$handler});",
        ];
    }
}
