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
 * Writes var/cache/config.php: the configuration of the current APP_ENV with env() resolved, which
 * the application then reads in place of the files. Sections whose files hold closures or objects
 * stay with their files. Run it when deploying, and again after changing a configuration file or
 * .env; the application sets a cache aside that no longer matches them.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ConfigCacheCommand extends Command
{
    public function __construct(private readonly Application $application)
    {
        parent::__construct('config:cache');
    }

    protected function configure(): void
    {
        $this->setDescription('Write the configuration cache (' . ConfigurationCache::PATH . ') for the current APP_ENV.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $this->application->getEnvironment();
        try {
            $result = (new ConfigurationCache($this->application->getRootDir()))->build($environment);
        } catch (RuntimeException $e) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($e->getMessage());

            return self::FAILURE;
        }
        $output->writeln(sprintf('Configuration cached in %s for APP_ENV=%s: %d section(s).', ConfigurationCache::PATH, $environment, count($result['cached'])));
        if ($result['live'] !== []) {
            $files = array_map(static fn(string $key): string => "config/{$key}.php", $result['live']);
            $output->writeln('Read from the files on each request, because they hold closures or objects: ' . implode(', ', $files) . '.');
        }
        $output->writeln('Values from the environment are fixed in the cache. After changing a configuration file or .env, run config:cache again; the application sets aside a cache that no longer matches them.');

        return self::SUCCESS;
    }
}
