<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Console;

use Crystoline\Xpsr\Console\Commands\AbstractMakeCommand;
use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Doctrine\Inflector\InflectorFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a Cycle entity in app/Entity, for the table Cycle would choose (Task: tasks).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeEntityCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator->withStubDirectory(dirname(__DIR__) . '/Resources/stubs'), 'make:entity');
    }

    protected function kind(): string
    {
        return 'entity';
    }

    protected function subNamespace(): string
    {
        return 'Entity';
    }

    protected function example(): string
    {
        return 'Task';
    }

    protected function replacements(GeneratedClass $target, InputInterface $input, SymfonyStyle $io): array
    {
        return ['table' => self::table($target)];
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        return [
            'Next: add the columns as #[Column] properties, then write the migration from the schema:',
            "    php xpsr make:migration create_{$replacements['table']}_table --diff",
        ];
    }

    private static function table(GeneratedClass $target): string
    {
        $inflector = InflectorFactory::create()->build();

        return $inflector->pluralize($inflector->tableize($target->shortName));
    }
}
