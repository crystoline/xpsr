<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests;

use Crystoline\Xpsr\Auth\OAuth2\Console\KeysCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\MakeOAuthTablesCommand;
use Crystoline\Xpsr\Auth\OAuth2\Keys;
use Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures\Project;
use Crystoline\Xpsr\Database\MigratorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project(keys: false, tables: false);
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testKeysMakesTheKeyPairAndTheEncryptionKey(): void
    {
        $root = $this->project->root;
        $keys = new CommandTester($this->project->application->get(KeysCommand::class));
        self::assertSame(0, $keys->execute([]));
        $output = $keys->getDisplay();
        self::assertStringContainsString('Wrote var/oauth/private.key and var/oauth/public.key.', $output);
        self::assertStringContainsString('Set OAUTH_ENCRYPTION_KEY in .env to:', $output);
        self::assertMatchesRegularExpression('/^def[0-9a-f]{20,}$/m', $output);
        self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', (string) file_get_contents($root . '/var/oauth/private.key'));
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', (string) file_get_contents($root . '/var/oauth/public.key'));
        self::assertSame('0600', substr(sprintf('%o', fileperms($root . '/var/oauth/private.key')), -4));
        self::assertFileDoesNotExist($root . '/.env');
        $service = $this->project->application->get(Keys::class);
        self::assertStringEndsWith($root . '/var/oauth/private.key', (string) $service->privateKey()->getKeyPath());
        self::assertStringContainsString('PUBLIC KEY', $service->publicKey()->getKeyContents());

        self::assertSame(1, $keys->execute([]));
        self::assertStringContainsString('[ERROR] The key files exist already: var/oauth/private.key and', $keys->getDisplay());
        self::assertStringContainsString('Use --force to replace them', $keys->getDisplay());

        file_put_contents($root . '/.env', "APP_KEY=x\n");
        self::assertSame(0, $keys->execute(['--force' => true, '--write' => true]));
        self::assertStringContainsString('Set OAUTH_ENCRYPTION_KEY in .env.', $keys->getDisplay());
        $env = (string) file_get_contents($root . '/.env');
        self::assertMatchesRegularExpression('/^APP_KEY=x\nOAUTH_ENCRYPTION_KEY=def[0-9a-f]+\n$/', $env);

        unlink($root . '/var/oauth/private.key');
        unlink($root . '/var/oauth/public.key');
        self::assertSame(1, $keys->execute(['--write' => true]));
        self::assertStringContainsString('[ERROR] OAUTH_ENCRYPTION_KEY is already set in .env. Use --force to replace it', $keys->getDisplay());
        self::assertSame($env, file_get_contents($root . '/.env'), 'Nothing changed.');
        self::assertFileDoesNotExist($root . '/var/oauth/private.key', 'Checked before anything is written.');

        self::assertSame(0, $keys->execute(['--force' => true, '--write' => true]));
        self::assertNotSame($env, file_get_contents($root . '/.env'));
        self::assertSame(1, preg_match_all('/^OAUTH_ENCRYPTION_KEY=/m', (string) file_get_contents($root . '/.env')), 'Replaced in place.');
    }

    public function testMakeOAuthTablesWritesTheMigrationOnce(): void
    {
        $make = new CommandTester($this->project->application->get(MakeOAuthTablesCommand::class));
        self::assertSame(0, $make->execute([]));
        self::assertMatchesRegularExpression('#Created migrations/\d{14}_create_oauth_clients_table\.php#', $make->getDisplay());
        self::assertStringContainsString('Next: run php xpsr migrate, then php xpsr oauth:keys --write.', $make->getDisplay());
        $files = glob($this->project->root . '/migrations/*.php') ?: [];
        self::assertCount(1, $files);
        $contents = (string) file_get_contents($files[0]);
        self::assertStringContainsString("setUnquotedName('oauth_clients')", $contents);
        self::assertStringContainsString("setUnquotedName('oauth_refresh_tokens')", $contents);
        self::assertStringContainsString("setUnquotedName('oauth_access_tokens_user')", $contents);
        self::assertStringNotContainsString('{{', $contents);

        self::assertSame(1, $make->execute([]));
        self::assertStringContainsString('A migration for the OAuth2 tables already exists', $make->getDisplay());

        $this->project->application->get(MigratorFactory::class)->create()->migrate();
        $tables = $this->project->db()->createSchemaManager()->listTableNames();
        foreach (['oauth_clients', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes'] as $table) {
            self::assertContains($table, $tables);
        }
    }
}
