<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes an API token by its ID.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class RevokeTokenCommand extends Command
{
    public function __construct(private readonly TokenGuards $guards)
    {
        parent::__construct('auth:token-revoke');
    }

    protected function configure(): void
    {
        $this->setDescription('Revoke an API token.')
            ->addArgument('id', InputArgument::REQUIRED, 'The token\'s ID, as auth:token printed it.')
            ->addOption('guard', null, InputOption::VALUE_REQUIRED, 'The token or sanctum guard whose table has the token. Default: the first such guard.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $id = (string) $input->getArgument('id');
        if (!ctype_digit($id)) {
            $errors->error('The token ID must be a number.');

            return self::INVALID;
        }
        $guard = $input->getOption('guard');
        try {
            $guard = $this->guards->guard(is_string($guard) ? $guard : null);
        } catch (InvalidArgumentException $exception) {
            $errors->error($exception->getMessage());

            return self::INVALID;
        }
        if (!$this->guards->store($guard)->revoke((int) $id)) {
            $errors->error("There is no token {$id}.");

            return self::FAILURE;
        }
        $output->writeln("Revoked token {$id}.");

        return self::SUCCESS;
    }
}
