<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Console;

use Crystoline\Xpsr\Console\Commands\AbstractMakeCommand;
use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an Eloquent model in app/Model, for the table Eloquent would choose (Task: tasks).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeModelCommand extends AbstractMakeCommand
{
    public function __construct(StubGenerator $generator)
    {
        parent::__construct($generator->withStubDirectory(dirname(__DIR__) . '/Resources/stubs'), 'make:model');
    }

    protected function kind(): string
    {
        return 'model';
    }

    protected function subNamespace(): string
    {
        return 'Model';
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
        $table = self::table($target);

        return [
            "Next: create the {$table} table with a migration, then list the columns create() may set in \$fillable:",
            "    php xpsr make:migration create_{$table}_table --create={$table}",
        ];
    }

    private static function table(GeneratedClass $target): string
    {
        return Str::snake(Str::pluralStudly($target->shortName));
    }
}
