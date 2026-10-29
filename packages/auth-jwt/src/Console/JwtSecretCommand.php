<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt\Console;

use Crystoline\Xpsr\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes a random secret for HS256 to HS512 JWTs (64 bytes, base64), prints it, and with --write
 * sets AUTH_JWT_SECRET in .env.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class JwtSecretCommand extends Command
{
    public function __construct(private readonly Application $application)
    {
        parent::__construct('auth:jwt-secret');
    }

    protected function configure(): void
    {
        $this->setDescription('Make a secret for signing JWTs.')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Set AUTH_JWT_SECRET in .env.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With --write, replace a secret that is already set. Every issued token stops working.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $secret = 'base64:' . base64_encode(random_bytes(64));
        if (!$input->getOption('write')) {
            $output->writeln($secret);

            return self::SUCCESS;
        }
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $file = $this->application->getRootDir() . '/.env';
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        $line = "AUTH_JWT_SECRET={$secret}";
        if (preg_match('/^AUTH_JWT_SECRET=(.*)$/m', $contents, $match)) {
            if (trim($match[1], " \t\"'") !== '' && !$input->getOption('force')) {
                $errors->error('AUTH_JWT_SECRET is already set in .env. Use --force to replace it; every issued token then stops working.');

                return self::FAILURE;
            }
            $contents = (string) preg_replace('/^AUTH_JWT_SECRET=.*$/m', $line, $contents, 1);
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (@file_put_contents($file, $contents) === false) {
            $errors->error('Could not write .env.');

            return self::FAILURE;
        }
        $output->writeln('Set AUTH_JWT_SECRET in .env.');

        return self::SUCCESS;
    }
}
