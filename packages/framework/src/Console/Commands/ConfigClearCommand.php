<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\ConfigurationCache;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes var/cache/config.php, so that the configuration files are read on each request again.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ConfigClearCommand extends Command
{
    public function __construct(private readonly Application $application)
    {
        parent::__construct('config:clear');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove the configuration cache (' . ConfigurationCache::PATH . ').');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $removed = (new ConfigurationCache($this->application->getRootDir()))->clear();
        } catch (RuntimeException $e) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($e->getMessage());

            return self::FAILURE;
        }
        $output->writeln($removed
            ? 'Removed ' . ConfigurationCache::PATH . '. The configuration files are read on each request.'
            : 'There is no configuration cache (' . ConfigurationCache::PATH . ').');

        return self::SUCCESS;
    }
}
