<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle\Console;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Orm\Cycle\SchemaCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes the cached schema, so that the next request compiles it from the entities.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SchemaClearCommand extends Command
{
    public function __construct(private readonly SchemaCache $cache, private readonly Application $application)
    {
        parent::__construct('cycle:schema:clear');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove the cached ORM schema.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->cache->file();
        if ($file === null) {
            $output->writeln('cycle.schema_cache is null, so there is no cached schema to remove.');

            return self::SUCCESS;
        }
        $root = rtrim($this->application->getRootDir(), '/') . '/';
        $display = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        $output->writeln(OutputFormatter::escape($this->cache->clear() ? "Removed {$display}." : "There is no cached schema at {$display}."));

        return self::SUCCESS;
    }
}
