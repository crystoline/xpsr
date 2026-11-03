<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Console;

use Crystoline\Xpsr\Auth\OAuth2\Clients;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the registered clients.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class ListClientsCommand extends Command
{
    public function __construct(private readonly Clients $clients)
    {
        parent::__construct('oauth:clients');
    }

    protected function configure(): void
    {
        $this->setDescription('List the registered OAuth2 clients.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the clients as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clients = $this->clients->all();
        if ($input->getOption('json')) {
            $rows = [];
            foreach ($clients as $client) {
                $rows[] = [
                    'id' => $client->id,
                    'name' => $client->name,
                    'confidential' => $client->confidential,
                    'grants' => $client->grants,
                    'redirect_uris' => $client->redirectUris,
                    'scopes' => $client->scopes,
                    'created_at' => $client->createdAt->format(DATE_ATOM),
                    'revoked_at' => $client->revokedAt?->format(DATE_ATOM),
                ];
            }
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($clients === []) {
            $output->writeln('No clients are registered. Register one with php xpsr oauth:client.');

            return self::SUCCESS;
        }
        $rows = [];
        foreach ($clients as $client) {
            $rows[] = [
                $client->id,
                $client->name,
                $client->confidential ? 'confidential' : 'public',
                implode(' ', $client->grants),
                implode(' ', $client->redirectUris),
                $client->scopes === null ? '(all)' : implode(' ', $client->scopes),
                $client->revokedAt === null ? '' : $client->revokedAt->format('Y-m-d H:i'),
            ];
        }
        (new SymfonyStyle($input, $output))->table(['ID', 'Name', 'Type', 'Grants', 'Redirect URIs', 'Scopes', 'Revoked'], $rows);

        return self::SUCCESS;
    }
}
