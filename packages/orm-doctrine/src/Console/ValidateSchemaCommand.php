<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Console;

use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Schema\SchemaDiff;
use Crystoline\Xpsr\Orm\Doctrine\EntitySchemaProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the entity mapping, and that the database matches it. Unlike Doctrine's own command, the
 * database check compares only the tables the entities describe, as make:migration --diff does,
 * so the migrations table and tables managed by hand do not count as differences.
 *
 * Exit status: 0 when both checks pass, 1 when the mapping is invalid, 2 when the database
 * differs, 3 when both fail.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ValidateSchemaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntitySchemaProvider $schema,
        private readonly ConnectionManager $connections,
    ) {
        parent::__construct('orm:validate-schema');
    }

    protected function configure(): void
    {
        $this->setDescription('Check the entity mapping, and that the database matches it.')
            ->addOption('skip-mapping', null, InputOption::VALUE_NONE, 'Do not check the mapping.')
            ->addOption('skip-sync', null, InputOption::VALUE_NONE, 'Do not compare the database with the mapping.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = 0;
        if (!$input->getOption('skip-mapping')) {
            $io->section('Mapping');
            $errors = (new SchemaValidator($this->entityManager))->validateMapping();
            if ($errors === []) {
                $io->success('The mapping is valid.');
            } else {
                foreach ($errors as $class => $messages) {
                    $io->error(OutputFormatter::escape($class) . ":\n" . implode("\n", array_map(OutputFormatter::escape(...), $messages)));
                }
                $status |= 1;
            }
        }
        if (!$input->getOption('skip-sync')) {
            $io->section('Database');
            $diff = SchemaDiff::between($this->connections->connection($this->schema->connection()), $this->schema->schema());
            if ($diff->isEmpty()) {
                $io->success('The database matches the mapping.');
            } else {
                $io->error(sprintf('The database differs from the mapping: %d statement(s) needed. Write them with php xpsr make:migration <name> --diff.', count($diff->up)));
                $status |= 2;
            }
        }

        return $status;
    }
}
