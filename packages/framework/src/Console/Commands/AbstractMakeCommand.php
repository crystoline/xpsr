<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Console\Generator\GeneratedClass;
use Crystoline\Console\Generator\GeneratorException;
use Crystoline\Console\Generator\StubGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared behavior for make:* commands.
 *
 * Invalid input returns 2 (INVALID); a conflict or filesystem failure returns 1 (FAILURE).
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
abstract class AbstractMakeCommand extends Command
{
    public function __construct(protected readonly StubGenerator $generator, string $name)
    {
        parent::__construct($name);
    }

    /** A short label such as "controller". */
    abstract protected function kind(): string;

    /** The namespace below the application namespace, such as "Http\Controller". */
    abstract protected function subNamespace(): string;

    /** An example class name for help text. */
    abstract protected function example(): string;

    /**
     * Instructions printed after a successful write.
     *
     * @param array<string, string> $replacements
     * @return list<string>
     */
    abstract protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array;

    protected function stub(InputInterface $input): string
    {
        return $this->kind();
    }

    /**
     * Extra stub values. Throw InvalidArgumentException for invalid options.
     *
     * @return array<string, string>
     */
    protected function replacements(GeneratedClass $target, InputInterface $input, SymfonyStyle $io): array
    {
        return [];
    }

    protected function configure(): void
    {
        $namespace = $this->generator->getNamespace() . '\\' . $this->subNamespace();
        $this->setDescription("Create a {$this->kind()} class.")
            ->addArgument('name', InputArgument::REQUIRED, "Class name relative to {$namespace}, for example {$this->example()}.")
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace the file if it already exists.');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $name = $input->getArgument('name');
        if ($name === null || $name === '') {
            $input->setArgument('name', (new SymfonyStyle($input, $output))->ask(ucfirst($this->kind()) . ' class name'));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();
        $name = $input->getArgument('name');
        try {
            $target = $this->generator->resolve($this->subNamespace(), is_string($name) ? $name : '');
            $replacements = $this->replacements($target, $input, $io);
        } catch (InvalidArgumentException $exception) {
            $errors->error($exception->getMessage());

            return self::INVALID;
        }

        try {
            $replaced = $this->generator->write($target, $this->stub($input), $replacements, (bool) $input->getOption('force'));
        } catch (GeneratorException $exception) {
            $errors->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(OutputFormatter::escape(sprintf(
            '%s %s (%s)',
            $replaced ? 'Replaced' : 'Created',
            $target->relativePath,
            $target->className(),
        )));
        foreach ($this->nextSteps($target, $replacements, $input) as $line) {
            $output->writeln(OutputFormatter::escape($line));
        }

        return self::SUCCESS;
    }
}
