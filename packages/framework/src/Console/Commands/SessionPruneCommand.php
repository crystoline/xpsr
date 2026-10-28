<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Session\SessionStoreInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md. */
final class SessionPruneCommand extends Command
{
    public function __construct(private readonly SessionStoreInterface $store)
    {
        parent::__construct('session:prune');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove expired sessions from the session store.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Removed %d expired session(s).', $this->store->prune()));

        return self::SUCCESS;
    }
}
