<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Console;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\OAuth2\Keys;
use Defuse\Crypto\Key;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes the RSA key pair that signs access tokens (oauth.private_key and oauth.public_key,
 * written as files only their owner can read) and the key that encrypts authorization codes and
 * refresh tokens: printed, or with --write set as OAUTH_ENCRYPTION_KEY in .env.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class KeysCommand extends Command
{
    private const VARIABLE = 'OAUTH_ENCRYPTION_KEY';

    public function __construct(private readonly Keys $keys, private readonly Application $application)
    {
        parent::__construct('oauth:keys');
    }

    protected function configure(): void
    {
        $this->setDescription('Make the keys that sign access tokens and encrypt refresh tokens.')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Set OAUTH_ENCRYPTION_KEY in .env.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace keys that exist. Every issued token, code, and refresh token then stops working.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $force = (bool) $input->getOption('force');
        $write = (bool) $input->getOption('write');
        $private = $this->keys->privateKeyPath();
        $public = $this->keys->publicKeyPath();
        $display = $this->keys->display($private) . ' and ' . $this->keys->display($public);
        if ((is_file($private) || is_file($public)) && !$force) {
            $errors->error("The key files exist already: {$display}. Use --force to replace them; every issued token then stops working.");

            return self::FAILURE;
        }
        $env = $this->application->getRootDir() . '/.env';
        $contents = is_file($env) ? (string) file_get_contents($env) : '';
        $pattern = '/^' . self::VARIABLE . '=(.*)$/m';
        if ($write && !$force && preg_match($pattern, $contents, $match) && trim($match[1], " \t\"'") !== '') {
            $errors->error(self::VARIABLE . ' is already set in .env. Use --force to replace it; every refresh token and authorization code then stops working.');

            return self::FAILURE;
        }
        $pair = self::generate();
        if ($pair === null) {
            $errors->error('Could not generate an RSA key pair: ' . (openssl_error_string() ?: 'OpenSSL gave no reason') . '.');

            return self::FAILURE;
        }
        foreach ([$private => $pair[0], $public => $pair[1]] as $file => $pem) {
            $directory = dirname($file);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                $errors->error("Could not create the directory {$this->keys->display($directory)}/.");

                return self::FAILURE;
            }
            if (@file_put_contents($file, $pem) === false || !@chmod($file, 0600)) {
                $errors->error("Could not write {$this->keys->display($file)}.");

                return self::FAILURE;
            }
        }
        $output->writeln(OutputFormatter::escape("Wrote {$display}."));
        $key = Key::createNewRandomKey()->saveToAsciiSafeString();
        if (!$write) {
            $output->writeln('Set ' . self::VARIABLE . ' in .env to:');
            $output->writeln($key);

            return self::SUCCESS;
        }
        $line = self::VARIABLE . "={$key}";
        if (preg_match($pattern, $contents)) {
            $contents = (string) preg_replace($pattern, $line, $contents, 1);
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (@file_put_contents($env, $contents) === false) {
            $errors->error('Could not write .env.');

            return self::FAILURE;
        }
        $output->writeln('Set ' . self::VARIABLE . ' in .env.');

        return self::SUCCESS;
    }

    /** @return array{string, string}|null the private and the public key, as PEM */
    private static function generate(): ?array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false || !openssl_pkey_export($resource, $private)) {
            return null;
        }
        $details = openssl_pkey_get_details($resource);
        $public = is_array($details) ? ($details['key'] ?? null) : null;

        return is_string($public) ? [(string) $private, $public] : null;
    }
}
