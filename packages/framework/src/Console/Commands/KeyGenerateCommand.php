<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Console\Commands;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Encryption\Encrypter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes a random encryption key for app.cipher, prints it, and with --write sets APP_KEY in .env.
 * A key that is already set is only replaced with --force.
 */
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly Application $application, private readonly Configuration $config)
    {
        parent::__construct('key:generate');
    }

    protected function configure(): void
    {
        $this->setDescription('Make an encryption key (APP_KEY).')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Set APP_KEY in .env.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With --write, replace a key that is already set. Data encrypted with it can then only be read with the old key in APP_PREVIOUS_KEYS.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $cipher = $this->config->get('app.cipher', 'AES-256-CBC');
        if (!is_string($cipher) || !isset(Encrypter::CIPHERS[strtoupper($cipher)])) {
            $errors->error('app.cipher must be one of ' . implode(', ', array_keys(Encrypter::CIPHERS)) . '.');

            return self::FAILURE;
        }
        $key = Encrypter::generateKey($cipher);
        if (!$input->getOption('write')) {
            $output->writeln($key);

            return self::SUCCESS;
        }
        $file = $this->application->getRootDir() . '/.env';
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        $line = "APP_KEY={$key}";
        if (preg_match('/^APP_KEY=(.*)$/m', $contents, $match)) {
            if (trim($match[1], " \t\"'") !== '' && !$input->getOption('force')) {
                $errors->error('APP_KEY is already set in .env. Use --force to replace it; data encrypted with the old key can then only be read with the old key in APP_PREVIOUS_KEYS.');

                return self::FAILURE;
            }
            $contents = (string) preg_replace('/^APP_KEY=.*$/m', $line, $contents, 1);
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (@file_put_contents($file, $contents) === false) {
            $errors->error('Could not write .env.');

            return self::FAILURE;
        }
        $output->writeln('Set APP_KEY in .env.');

        return self::SUCCESS;
    }
}
