<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Console;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Orm\Cycle\SchemaBuilder;
use Crystoline\Xpsr\Orm\Cycle\SchemaCache;
use Cycle\ORM\Relation;
use Cycle\ORM\SchemaInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the schema compiled from the entities: each role with its class, table, columns, and
 * relations, and whether the schema cache is in use.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SchemaCommand extends Command
{
    public function __construct(
        private readonly SchemaBuilder $builder,
        private readonly SchemaCache $cache,
        private readonly Application $application,
    ) {
        parent::__construct('cycle:schema');
    }

    protected function configure(): void
    {
        $this->setDescription('Show the ORM schema compiled from the entities.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the schema as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = $this->builder->compile()->schema;
        if ($input->getOption('json')) {
            $output->writeln(json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($schema === []) {
            $output->writeln('No entities found. Put #[Entity] classes in app/Entity, or name their directories in cycle.entities.');

            return self::SUCCESS;
        }
        $rows = [];
        foreach ($schema as $role => $definition) {
            $columns = $definition[SchemaInterface::COLUMNS] ?? [];
            $relations = $definition[SchemaInterface::RELATIONS] ?? [];
            $links = [];
            foreach (is_array($relations) ? $relations : [] as $name => $relation) {
                $target = is_array($relation) ? ($relation[Relation::TARGET] ?? '?') : '?';
                $links[] = $name . ' -> ' . (is_string($target) ? $target : '?');
            }
            $rows[] = [
                $role,
                self::text($definition[SchemaInterface::ENTITY] ?? ''),
                self::text($definition[SchemaInterface::DATABASE] ?? '') . '.' . self::text($definition[SchemaInterface::TABLE] ?? ''),
                implode(', ', array_map(strval(...), array_keys(is_array($columns) ? $columns : []))),
                implode(', ', $links),
            ];
        }
        $io = new SymfonyStyle($input, $output);
        $io->table(['Role', 'Class', 'Table', 'Columns', 'Relations'], $rows);
        $file = $this->cache->file();
        $root = rtrim($this->application->getRootDir(), '/') . '/';
        $output->writeln(match (true) {
            $file === null => 'Schema cache: none (cycle.schema_cache is null); the schema is compiled on each request.',
            $this->cache->exists() => 'Schema cache: ' . (str_starts_with($file, $root) ? substr($file, strlen($root)) : $file) . ' (present).',
            default => 'Schema cache: ' . (str_starts_with($file, $root) ? substr($file, strlen($root)) : $file) . ' (absent; run php xpsr cycle:schema:cache for production).',
        });

        return self::SUCCESS;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
