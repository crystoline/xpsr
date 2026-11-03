<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Cycle\Annotated\Embeddings;
use Cycle\Annotated\Entities;
use Cycle\Annotated\Locator\TokenizerEmbeddingLocator;
use Cycle\Annotated\Locator\TokenizerEntityLocator;
use Cycle\Annotated\MergeColumns;
use Cycle\Annotated\MergeIndexes;
use Cycle\Annotated\TableInheritance;
use Cycle\Database\DatabaseManager;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator\ForeignKeys;
use Cycle\Schema\Generator\GenerateModifiers;
use Cycle\Schema\Generator\GenerateRelations;
use Cycle\Schema\Generator\GenerateTypecast;
use Cycle\Schema\Generator\RenderModifiers;
use Cycle\Schema\Generator\RenderRelations;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Generator\ResetTables;
use Cycle\Schema\Generator\ValidateEntities;
use Cycle\Schema\GeneratorInterface;
use Cycle\Schema\Registry;
use Spiral\Tokenizer\Config\TokenizerConfig;
use Spiral\Tokenizer\Tokenizer;

/**
 * Compiles the ORM schema from the entity classes in cycle.entities, with Cycle's attribute
 * reader, relations, behaviors, and typecasting, and keeps the tables the entities declare for
 * make:migration --diff.
 *
 * @internal
 */
final class SchemaBuilder
{
    /** @param list<GeneratorInterface> $generators more generators, run after the entities are read and before the relations are built */
    public function __construct(
        private readonly CycleOptions $options,
        private readonly DatabaseManager $databases,
        private readonly array $generators = [],
    ) {}

    public function compile(): CompiledSchema
    {
        if ($this->options->entityPaths === []) {
            return new CompiledSchema([], []);
        }
        $classes = (new Tokenizer(new TokenizerConfig(['directories' => $this->options->entityPaths, 'exclude' => []])))->classLocator();
        $registry = new Registry($this->databases);
        $schema = (new Compiler())->compile($registry, [
            new ResetTables(),
            new Embeddings(new TokenizerEmbeddingLocator($classes)),
            new Entities(new TokenizerEntityLocator($classes)),
            new TableInheritance(),
            new MergeColumns(),
            ...$this->generators,
            new GenerateRelations(),
            new GenerateModifiers(),
            new ValidateEntities(),
            new RenderTables(),
            new RenderRelations(),
            new RenderModifiers(),
            new ForeignKeys(),
            new MergeIndexes(),
            new GenerateTypecast(),
        ]);
        $tables = [];
        foreach ($registry as $entity) {
            if ($registry->hasTable($entity)) {
                $tables[$registry->getDatabase($entity)][$registry->getTable($entity)] = $registry->getTableSchema($entity);
            }
        }

        return new CompiledSchema($schema, array_map(array_values(...), $tables));
    }
}
