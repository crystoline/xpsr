<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Console\CommandRegistry;
use Crystoline\Console\Generator\GeneratedClass;
use Crystoline\Console\Generator\StubGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class MakeCommandCommand extends AbstractMakeCommand
{
    private const DEFAULT_DESCRIPTION = 'Command description.';

    public function __construct(StubGenerator $generator, private readonly CommandRegistry $commands)
    {
        parent::__construct($generator, 'make:command');
    }

    /** SendReportCommand becomes app:send-report. */
    public static function deriveName(string $class): string
    {
        $base = preg_replace('/Command\z/', '', $class);
        $base = $base === null || $base === '' ? $class : $base;
        $words = preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z0-9])([A-Z])/'], '$1-$2', $base) ?? $base;

        return 'app:' . trim((string) preg_replace('/[_-]+/', '-', strtolower($words)), '-');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('command', null, InputOption::VALUE_REQUIRED, 'Console name, such as app:send-report. Derived from the class name by default.')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description shown by list.', self::DEFAULT_DESCRIPTION);
    }

    protected function kind(): string
    {
        return 'command';
    }

    protected function subNamespace(): string
    {
        return 'Console\Commands';
    }

    protected function example(): string
    {
        return 'SendReportCommand';
    }

    protected function replacements(GeneratedClass $target, InputInterface $input, SymfonyStyle $io): array
    {
        $name = $input->getOption('command') ?? self::deriveName($target->shortName);
        if (!is_string($name) || !preg_match(CommandRegistry::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException(sprintf(
                'Command name "%s" is not valid. Use lowercase words separated by colons, such as app:send-report, and pass it with --command.',
                is_string($name) ? $name : '',
            ));
        }
        $description = $input->getOption('description');
        if (!is_string($description) || preg_match('/[\x00-\x1F\x7F]/', $description)) {
            throw new InvalidArgumentException('The description must be a single line of text.');
        }
        if (isset($this->commands->all()[$name])) {
            $io->getErrorStyle()->warning("A command named \"{$name}\" is already registered. Choose another name before registering this class.");
        }

        // Exported as PHP string literals so quotes and backslashes survive.
        return ['command' => var_export($name, true), 'description' => var_export($description, true)];
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        $class = '\\' . $target->className() . '::class';

        return [
            'Next: register it in a provider, as App\Registry\CommandServiceRegistry does:',
            "    register(): \$this->container->bind({$class});",
            '    boot():     $this->container->get(\Crystoline\Console\CommandRegistry::class)',
            "                    ->register({$replacements['command']}, {$class}, {$replacements['description']});",
        ];
    }
}
