<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Database\Auth\TokenRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes an API token by its ID.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class RevokeTokenCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens)
    {
        parent::__construct('auth:token-revoke');
    }

    protected function configure(): void
    {
        $this->setDescription('Revoke an API token.')
            ->addArgument('id', InputArgument::REQUIRED, 'The token\'s ID, as auth:token printed it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        if (!ctype_digit($id)) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error('The token ID must be a number.');

            return self::INVALID;
        }
        if (!$this->tokens->revoke((int) $id)) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error("There is no token {$id}.");

            return self::FAILURE;
        }
        $output->writeln("Revoked token {$id}.");

        return self::SUCCESS;
    }
}
