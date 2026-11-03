<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Console;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Orm\Cycle\SchemaBuilder;
use Crystoline\Xpsr\Orm\Cycle\SchemaCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compiles the schema from the entities and writes it to the cache file, for production.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SchemaCacheCommand extends Command
{
    public function __construct(
        private readonly SchemaBuilder $builder,
        private readonly SchemaCache $cache,
        private readonly Application $application,
    ) {
        parent::__construct('cycle:schema:cache');
    }

    protected function configure(): void
    {
        $this->setDescription('Compile the ORM schema from the entities and cache it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->cache->file();
        if ($file === null) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error('cycle.schema_cache is null, so there is no cache file to write.');

            return self::INVALID;
        }
        $schema = $this->builder->compile()->schema;
        $this->cache->write($schema);
        $root = rtrim($this->application->getRootDir(), '/') . '/';
        $output->writeln(OutputFormatter::escape(sprintf(
            'Compiled the schema of %d %s to %s.',
            count($schema),
            count($schema) === 1 ? 'entity' : 'entities',
            str_starts_with($file, $root) ? substr($file, strlen($root)) : $file,
        )));

        return self::SUCCESS;
    }
}
