<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\Commands\KeyGenerateCommand;
use Crystoline\Xpsr\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class KeyGenerateCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr-key-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/.env')) {
            unlink($this->root . '/.env');
        }
        rmdir($this->root);
    }

    public function testPrintsAKeyForTheCipher(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/\Abase64:[A-Za-z0-9+\/]{43}=\n\z/', $tester->getDisplay());
        self::assertSame(32, strlen((string) base64_decode(substr(trim($tester->getDisplay()), 7), true)));
        self::assertFileDoesNotExist($this->root . '/.env');

        $short = $this->tester('AES-128-GCM');
        $short->execute([]);
        self::assertSame(16, strlen((string) base64_decode(substr(trim($short->getDisplay()), 7), true)));
    }

    // Each test builds its commands before writing .env: an Application loads .env into the
    // environment of the whole test run.
    public function testWriteFillsAnEmptyKeyAndAddsAMissingOne(): void
    {
        $tester = $this->tester();
        $again = $this->tester();
        file_put_contents($this->root . '/.env', "APP_NAME=Test\nAPP_KEY=\nAPP_DEBUG=true\n");
        self::assertSame(Command::SUCCESS, $tester->execute(['--write' => true]));
        self::assertSame("Set APP_KEY in .env.\n", $tester->getDisplay());
        $env = (string) file_get_contents($this->root . '/.env');
        self::assertMatchesRegularExpression('/\AAPP_NAME=Test\nAPP_KEY=base64:\S{44}\nAPP_DEBUG=true\n\z/', $env);
        self::assertSame(1, preg_match('/APP_KEY=(\S+)/', $env, $match));
        $encrypter = new Encrypter((string) base64_decode(substr($match[1], 7), true));
        self::assertSame('Ada', $encrypter->decryptString($encrypter->encryptString('Ada')), 'The key works with the encrypter.');

        file_put_contents($this->root . '/.env', 'APP_NAME=Test');
        $again->execute(['--write' => true]);
        self::assertMatchesRegularExpression('/\AAPP_NAME=Test\nAPP_KEY=base64:\S{44}\n\z/', (string) file_get_contents($this->root . '/.env'));
    }

    public function testASetKeyIsOnlyReplacedWithForce(): void
    {
        $refused = $this->tester();
        $forced = $this->tester();
        $env = "APP_KEY=base64:dGhlIG9sZCBrZXkgdGhhdCBzdGF5cyBpbiBwbGFjZSE=\nAPP_PREVIOUS_KEYS=\n";
        file_put_contents($this->root . '/.env', $env);
        self::assertSame(Command::FAILURE, $refused->execute(['--write' => true]));
        self::assertStringContainsString('APP_KEY is already set in .env. Use --force to replace it', (string) preg_replace('/\s+/', ' ', $refused->getDisplay()));
        self::assertSame($env, file_get_contents($this->root . '/.env'), 'Unchanged.');

        self::assertSame(Command::SUCCESS, $forced->execute(['--write' => true, '--force' => true]));
        $replaced = (string) file_get_contents($this->root . '/.env');
        self::assertStringNotContainsString('dGhlIG9sZCBrZXkg', $replaced);
        self::assertMatchesRegularExpression('/\AAPP_KEY=base64:\S{44}\nAPP_PREVIOUS_KEYS=\n\z/', $replaced, 'Only APP_KEY changes.');
    }

    public function testAnUnknownCipherIsReported(): void
    {
        $tester = $this->tester('DES');
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('app.cipher must be one of AES-128-CBC, AES-256-CBC, AES-128-GCM, AES-256-GCM.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private function tester(string $cipher = 'AES-256-CBC'): CommandTester
    {
        $application = new Application($this->root);
        $application->getConfig()->set('app.cipher', $cipher);

        return new CommandTester(new KeyGenerateCommand($application, $application->getConfig()));
    }
}
