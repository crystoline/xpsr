<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent\Tests;

use Crystoline\Xpsr\Encryption\Encrypter;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Project;
use Crystoline\Xpsr\Orm\Eloquent\Tests\Fixtures\Secret;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Illuminate\Encryption\Encrypter as LaravelEncrypter;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Eloquent's encrypted casts with app.key, checked against Laravel's own encrypter. */
final class EncryptedCastsTest extends TestCase
{
    private Project $project;

    private string $key;

    private LaravelEncrypter $laravel;

    protected function setUp(): void
    {
        $this->key = Encrypter::generateKey();
        $this->laravel = new LaravelEncrypter((string) base64_decode(substr($this->key, 7), true), 'AES-256-CBC');
        $this->project = $this->project($this->key);
    }

    protected function tearDown(): void
    {
        $this->project->db()->createSchemaManager()->dropTable('eloquent_secrets');
        $this->project->remove();
    }

    public function testEncryptedColumnsAreStoredInLaravelsFormat(): void
    {
        $secret = Secret::query()->create([
            'token' => 'sk_live_123',
            'details' => ['bank' => 'First', 'account' => '0123456789'],
            'tags' => ['vip', 'kyc'],
            'options' => ['notify' => true],
        ]);

        $row = $this->project->db()->fetchAssociative('SELECT token, details, tags, options FROM eloquent_secrets WHERE id = ?', [$secret->id]);
        self::assertIsArray($row);
        self::assertStringNotContainsString('sk_live_123', (string) $row['token'], 'Stored encrypted.');
        self::assertSame('sk_live_123', $this->laravel->decryptString((string) $row['token']), 'Laravel reads it.');
        self::assertSame(['bank' => 'First', 'account' => '0123456789'], json_decode($this->laravel->decryptString((string) $row['details']), true));
        self::assertSame(['vip', 'kyc'], json_decode($this->laravel->decryptString((string) $row['tags']), true));
        self::assertSame(['notify' => true], json_decode($this->laravel->decryptString((string) $row['options']), true));

        $read = Secret::query()->findOrFail($secret->id);
        self::assertSame('sk_live_123', $read->token);
        self::assertSame(['bank' => 'First', 'account' => '0123456789'], $read->details);
        self::assertSame(['vip', 'kyc'], $read->tags?->all());
        self::assertTrue($read->options['notify'] ?? false);
    }

    public function testColumnsThatLaravelEncryptedAreRead(): void
    {
        $this->project->db()->insert('eloquent_secrets', [
            'id' => 7,
            'token' => $this->laravel->encryptString('from Laravel'),
            'details' => $this->laravel->encryptString((string) json_encode(['plan' => 'gold'])),
            'tags' => $this->laravel->encryptString((string) json_encode(['legacy'])),
            'options' => $this->laravel->encryptString((string) json_encode(['locale' => 'en'])),
        ]);

        $secret = Secret::query()->findOrFail(7);
        self::assertSame('from Laravel', $secret->token);
        self::assertSame(['plan' => 'gold'], $secret->details);
        self::assertSame(['legacy'], $secret->tags?->all());
        self::assertSame('en', $secret->options['locale'] ?? null);
    }

    public function testColumnsUnderAPreviousKeyAreReadAndRewrittenWithTheNewKey(): void
    {
        $this->project->db()->createSchemaManager()->dropTable('eloquent_secrets');
        $this->project->remove();
        $newKey = Encrypter::generateKey();
        $this->project = $this->project($newKey, previousKeys: [$this->key]);
        $this->project->db()->insert('eloquent_secrets', ['id' => 8, 'token' => $this->laravel->encryptString('old key')]);

        $secret = Secret::query()->findOrFail(8);
        self::assertSame('old key', $secret->token);
        // With previous keys, assigning an encrypted attribute its own value re-encrypts it.
        $secret->token = $secret->token;
        $secret->save();
        $stored = (string) $this->project->db()->fetchOne('SELECT token FROM eloquent_secrets WHERE id = 8');
        $new = new LaravelEncrypter((string) base64_decode(substr($newKey, 7), true), 'AES-256-CBC');
        self::assertSame('old key', $new->decryptString($stored), 'Saved under the new key.');
    }

    public function testCryptAndSerializedValuesMatchLaravel(): void
    {
        self::assertSame(['amount' => 500, 'currency' => 'NGN'], $this->laravel->decrypt(Crypt::encrypt(['amount' => 500, 'currency' => 'NGN'])));
        self::assertSame(['amount' => 500], Crypt::decrypt($this->laravel->encrypt(['amount' => 500])));
        self::assertSame('text', Crypt::decryptString($this->laravel->encryptString('text')));
        self::assertSame(Crypt::getKey(), base64_decode(substr($this->key, 7), true));
    }

    public function testWithoutAKeyTheApplicationStartsAndEncryptedColumnsSayHowToMakeOne(): void
    {
        $this->project->db()->createSchemaManager()->dropTable('eloquent_secrets');
        $this->project->remove();
        $this->project = $this->project('');

        try {
            Secret::query()->create(['token' => 'sk_live_123']);
            self::fail('A column was encrypted without a key.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('No encryption key is set: APP_KEY is empty. Run php xpsr key:generate --write to make one.', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->project->db()->fetchOne('SELECT COUNT(*) FROM eloquent_secrets'));
    }

    /** @param list<string> $previousKeys */
    private function project(string $key, array $previousKeys = []): Project
    {
        $project = new Project();
        $project->application->getConfig()->set('app.key', $key);
        $project->application->getConfig()->set('app.previous_keys', $previousKeys);
        $project->boot();
        $schema = $project->db()->createSchemaManager();
        if ($schema->tableExists('eloquent_secrets')) {
            $schema->dropTable('eloquent_secrets');
        }
        $text = static fn(string $name): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::TEXT)->setNotNull(false)->create();
        $schema->createTable(Table::editor()
            ->setUnquotedName('eloquent_secrets')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                $text('token'),
                $text('details'),
                $text('tags'),
                $text('options'),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create());

        return $project;
    }
}
