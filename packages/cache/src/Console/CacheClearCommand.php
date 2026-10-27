<?php

declare(strict_types=1);

namespace Crystoline\Cache\Console;

use Crystoline\Cache\CacheServiceRegistry;
use Crystoline\Configuration;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes every item from the application cache. Only items under the configured namespace
 * are removed when storage is shared.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly Configuration $config,
    ) {
        parent::__construct('cache:clear');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove every item from the application cache.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $description = CacheServiceRegistry::describe($this->config);
        if (!$this->pool->clear()) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error("Could not clear the {$description}.");

            return self::FAILURE;
        }
        $output->writeln(OutputFormatter::escape("Cleared the {$description}."));

        return self::SUCCESS;
    }
}
