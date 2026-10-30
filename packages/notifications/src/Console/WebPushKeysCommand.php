<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Console;

use Crystoline\Xpsr\Application;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes a VAPID key pair for web push: a P-256 key, as VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in
 * URL-safe base64, the format Laravel's web push channel uses too.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class WebPushKeysCommand extends Command
{
    public function __construct(private readonly Application $application)
    {
        parent::__construct('webpush:keys');
    }

    /**
     * A new key pair.
     *
     * @return array{public: string, private: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('OpenSSL could not make a P-256 key: ' . (openssl_error_string() ?: 'no reason given') . '.');
        }
        $pad = static fn(string $bytes): string => str_pad($bytes, 32, "\0", STR_PAD_LEFT);
        $encode = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'public' => $encode("\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y'])),
            'private' => $encode($pad($details['ec']['d'])),
        ];
    }

    protected function configure(): void
    {
        $this->setDescription('Make a VAPID key pair for web push (VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY).')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in .env.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With --write, replace keys that are already set. Browsers subscribed with the old public key then get no pushes until they subscribe again.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $keys = self::generate();
        $lines = ['VAPID_PUBLIC_KEY' => $keys['public'], 'VAPID_PRIVATE_KEY' => $keys['private']];
        if (!$input->getOption('write')) {
            foreach ($lines as $name => $value) {
                $output->writeln("{$name}={$value}");
            }

            return self::SUCCESS;
        }
        $file = $this->application->getRootDir() . '/.env';
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        foreach (array_keys($lines) as $name) {
            if (preg_match("/^{$name}=(.*)$/m", $contents, $match) && trim($match[1], " \t\"'") !== '' && !$input->getOption('force')) {
                $errors->error('VAPID keys are already set in .env. Use --force to replace them; browsers subscribed with the old public key then get no pushes until they subscribe again.');

                return self::FAILURE;
            }
        }
        foreach ($lines as $name => $value) {
            $line = "{$name}={$value}";
            if (preg_match("/^{$name}=.*$/m", $contents)) {
                $contents = (string) preg_replace("/^{$name}=.*$/m", $line, $contents, 1);
            } else {
                $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
            }
        }
        if (@file_put_contents($file, $contents) === false) {
            $errors->error('Could not write .env.');

            return self::FAILURE;
        }
        $output->writeln('Set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in .env.');

        return self::SUCCESS;
    }
}
