<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Queue\Console;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Tells running workers to stop after their current job, through the application cache, so a
 * process supervisor starts them again with new code. Run it after each deploy.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class QueueRestartCommand extends Command
{
    public function __construct(private readonly ?CacheItemPoolInterface $cache = null)
    {
        parent::__construct('queue:restart');
    }

    protected function configure(): void
    {
        $this->setDescription('Tell workers to stop after their current job.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->cache === null) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error('queue:restart needs a shared cache: composer require crystoline/xpsr-cache, and add Crystoline\Xpsr\Cache\CacheServiceRegistry to app.registry.');

            return self::FAILURE;
        }
        $item = $this->cache->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        $this->cache->save($item->set(microtime(true)));
        $output->writeln('Workers will stop after their current job.');

        return self::SUCCESS;
    }
}
