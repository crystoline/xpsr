<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Console;

use Crystoline\Xpsr\Auth\OAuth2\Clients;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers a client and prints its ID and secret once.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class CreateClientCommand extends Command
{
    public function __construct(private readonly Clients $clients)
    {
        parent::__construct('oauth:client');
    }

    protected function configure(): void
    {
        $this->setDescription('Register an OAuth2 client and print its secret.')
            ->addArgument('name', InputArgument::REQUIRED, 'What the application is called on the consent page, such as "Mobile app".')
            ->addOption('redirect', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A redirect URI for the authorization code grant; repeat for several.')
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A grant the client may use (client_credentials, authorization_code, refresh_token); repeat for several. Default: authorization_code and refresh_token with --redirect, client_credentials without.')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A scope the client may ask for; repeat for several. Default: every configured scope.')
            ->addOption('public', null, InputOption::VALUE_NONE, 'A public client (a mobile or browser application): no secret, PKCE required.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $redirects = self::strings($input->getOption('redirect'));
        $grants = self::strings($input->getOption('grant'));
        $scopes = self::strings($input->getOption('scope'));
        try {
            $new = $this->clients->create(
                (string) $input->getArgument('name'),
                $redirects,
                $grants === [] ? null : $grants,
                $scopes === [] ? null : $scopes,
                !$input->getOption('public'),
            );
        } catch (InvalidArgumentException $exception) {
            $errors->error($exception->getMessage());

            return self::INVALID;
        }
        $client = $new->client;
        $output->writeln(OutputFormatter::escape(sprintf(
            'Registered the %s client "%s": grants %s; redirect URIs %s; scopes %s.',
            $client->confidential ? 'confidential' : 'public',
            $client->name,
            implode(', ', $client->grants),
            $client->redirectUris === [] ? 'none' : implode(', ', $client->redirectUris),
            $client->scopes === null ? 'every configured scope' : implode(', ', $client->scopes),
        )));
        $output->writeln("Client ID:     {$client->id}");
        if ($new->secret !== null) {
            $output->writeln("Client secret: {$new->secret}");
            $errors->writeln('The secret is shown only now.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
