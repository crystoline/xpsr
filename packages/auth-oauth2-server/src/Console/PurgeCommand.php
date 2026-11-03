<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Console;

use Crystoline\Xpsr\Auth\OAuth2\Tokens;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes expired authorization codes and expired or revoked tokens; for the scheduler.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class PurgeCommand extends Command
{
    public function __construct(private readonly Tokens $tokens)
    {
        parent::__construct('oauth:purge');
    }

    protected function configure(): void
    {
        $this->setDescription('Remove expired and revoked OAuth2 tokens and codes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->tokens->purge();
        $output->writeln("Removed {$removed} expired or revoked tokens and codes.");

        return self::SUCCESS;
    }
}
