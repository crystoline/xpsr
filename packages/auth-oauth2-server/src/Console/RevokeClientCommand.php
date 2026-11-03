<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Console;

use Crystoline\Xpsr\Auth\OAuth2\Clients;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Revokes a client and every token it holds.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class RevokeClientCommand extends Command
{
    public function __construct(private readonly Clients $clients)
    {
        parent::__construct('oauth:client-revoke');
    }

    protected function configure(): void
    {
        $this->setDescription('Revoke an OAuth2 client and its tokens.')
            ->addArgument('id', InputArgument::REQUIRED, 'The client ID, as oauth:clients lists it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $id = (string) $input->getArgument('id');
        $client = $this->clients->find($id);
        if ($client === null) {
            $errors->error(OutputFormatter::escape("There is no client {$id}."));

            return self::FAILURE;
        }
        if ($client->isRevoked()) {
            $output->writeln(OutputFormatter::escape("Client {$id} was revoked already."));

            return self::SUCCESS;
        }
        $this->clients->revoke($id);
        $output->writeln(OutputFormatter::escape("Revoked client {$id} ({$client->name}) and its tokens."));

        return self::SUCCESS;
    }
}
