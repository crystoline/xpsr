<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Database\Auth\DatabaseUserProvider;
use Crystoline\Xpsr\Database\Auth\TokenGuard;
use Crystoline\Xpsr\Database\Auth\TokenRepository;
use Crystoline\Xpsr\Database\Console\CreateTokenCommand;
use Crystoline\Xpsr\Database\Console\MakeTokenTableCommand;
use Crystoline\Xpsr\Database\Console\RevokeTokenCommand;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\MigratorFactory;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Users from a table and API tokens, through the application: auth.users driver "database" and
 * the "token" guard. Runs on the server in XPSR_TEST_PGSQL_URL or XPSR_TEST_MYSQL_URL when set,
 * otherwise on SQLite.
 */
final class DatabaseAuthTest extends TestCase
{
    private Workspace $workspace;
    private Application $application;
    private Connection $connection;
    private MockClock $clock;
    private string $suffix;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        $this->suffix = bin2hex(random_bytes(3));
        $this->clock = new MockClock('2026-10-29 12:00:00');
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $this->application = new Application($this->workspace->root);
        $config = $this->application->getConfig();
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, AuthServiceRegistry::class]);
        $config->set('database', [
            'default' => 'test',
            'connections' => ['test' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/auth.sqlite']],
            'migrations' => ['path' => 'migrations', 'table' => "migrations_{$this->suffix}"],
        ]);
        $config->set('auth', [
            'guards' => ['web' => ['driver' => 'session'], 'api' => ['driver' => 'token'], 'partner' => ['driver' => 'token']],
            'users' => ['driver' => 'database', 'table' => "people_{$this->suffix}", 'login' => 'email_address', 'password' => 'password_hash'],
            'tokens' => ['table' => "tokens_{$this->suffix}"],
        ]);
        $this->application->getContainer()->instance(ClockInterface::class, $this->clock);
        $this->application->boot();
        $this->connection = $this->application->get(Connection::class);

        $this->connection->createSchemaManager()->createTable(
            Table::editor()
                ->setUnquotedName("people_{$this->suffix}")
                ->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                    Column::editor()->setUnquotedName('email_address')->setTypeName(Types::STRING)->setLength(255)->create(),
                    Column::editor()->setUnquotedName('password_hash')->setTypeName(Types::STRING)->setLength(255)->create(),
                    Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(255)->create(),
                )
                ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->create(),
        );
        $this->connection->insert("people_{$this->suffix}", ['email_address' => 'ada@example.com', 'password_hash' => password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]), 'name' => 'Ada']);

        $make = new CommandTester($this->application->get(MakeTokenTableCommand::class));
        self::assertSame(Command::SUCCESS, $make->execute([]));
        self::assertStringContainsString("_create_tokens_{$this->suffix}_table.php", $make->getDisplay());
        self::assertCount(1, $this->application->get(MigratorFactory::class)->create()->migrate());
    }

    protected function tearDown(): void
    {
        $this->application->get(MigratorFactory::class)->create()->rollback();
        $schema = $this->connection->createSchemaManager();
        self::assertFalse($schema->tableExists("tokens_{$this->suffix}"), 'Rolling back drops the token table.');
        $schema->dropTable("people_{$this->suffix}");
        $schema->dropTable("migrations_{$this->suffix}");
        $this->connection->close();
        $this->workspace->remove();
    }

    public function testUsersAreFoundInTheTableWithoutTheirPassword(): void
    {
        $users = $this->application->get(AuthManager::class)->users();
        self::assertInstanceOf(DatabaseUserProvider::class, $users);
        $ada = $users->findByLogin('ada@example.com');
        self::assertInstanceOf(GenericUser::class, $ada);
        self::assertSame(1, $ada->authIdentifier(), 'Numeric IDs are integers on every driver.');
        self::assertSame('Ada', $ada->get('name'));
        self::assertArrayNotHasKey('password_hash', $ada->toArray());
        $byId = $users->findById(1);
        self::assertInstanceOf(GenericUser::class, $byId);
        self::assertSame('ada@example.com', $byId->get('email_address'));
        self::assertNull($users->findByLogin('nobody@example.com'));
        self::assertNull($users->findByLogin(''));
    }

    public function testSigningInUpgradesTheStoredHash(): void
    {
        $auth = $this->application->get(AuthManager::class);
        self::assertNotNull($auth->verify('ada@example.com', 'correct horse'));
        $stored = (string) $this->connection->fetchOne("SELECT password_hash FROM people_{$this->suffix} WHERE id = 1");
        self::assertFalse((new PasswordHasher())->needsRehash($stored));
        self::assertNotNull($auth->verify('ada@example.com', 'correct horse'), 'The new hash still matches.');
    }

    public function testTokensAuthenticateUntilRevokedOrExpired(): void
    {
        $tokens = $this->application->get(TokenRepository::class);
        $ada = $this->application->get(AuthManager::class)->users()->findById(1) ?? self::fail();
        $new = $tokens->create($ada, 'deploy script', ['tasks:read'], new DateTimeImmutable('2026-11-29 12:00:00'));
        self::assertMatchesRegularExpression('/\Axpsr_[0-9a-f]{64}\z/', $new->plainText);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tokens_{$this->suffix} WHERE token_hash = ?", [$new->plainText]), 'Only the hash is stored.');

        $guard = $this->application->get(AuthManager::class)->guard('api');
        self::assertInstanceOf(TokenGuard::class, $guard);
        $identity = $guard->authenticate($this->bearer($new->plainText));
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame([1, 'api', (string) $new->token->id], [$identity->user->authIdentifier(), $identity->guard, $identity->tokenId]);
        self::assertTrue($identity->hasScope('tasks:read'));
        self::assertFalse($identity->hasScope('tasks:write'));
        self::assertSame($this->clock->now()->getTimestamp(), $tokens->forUser($ada)[0]->lastUsedAt?->getTimestamp(), 'Use is recorded.');

        self::assertNull($guard->authenticate($this->bearer($new->plainText . 'x')));
        self::assertNull($guard->authenticate($this->bearer('xpsr_' . str_repeat('0', 64))));
        self::assertSame('Bearer error="invalid_token"', $guard->challenge($this->bearer('nope')));
        self::assertSame('Bearer', $guard->challenge(new ServerRequest('GET', '/')));

        $this->clock->modify('+31 days');
        self::assertNull($guard->authenticate($this->bearer($new->plainText)), 'Expired.');

        $forever = $tokens->create($ada, 'cli');
        self::assertSame(['*'], $forever->token->scopes);
        self::assertNotNull($guard->authenticate($this->bearer($forever->plainText)));
        self::assertTrue($tokens->revoke($forever->token->id));
        self::assertFalse($tokens->revoke($forever->token->id));
        self::assertNull($guard->authenticate($this->bearer($forever->plainText)));

        $tokens->create($ada, 'one');
        $tokens->create($ada, 'two');
        self::assertSame(['two', 'one', 'deploy script'], array_map(static fn($token): string => $token->name, $tokens->forUser($ada)));
        self::assertSame(3, $tokens->revokeAll($ada));
    }

    public function testTheCommandsCreateAndRevokeTokens(): void
    {
        $create = new CommandTester($this->application->get(CreateTokenCommand::class));
        self::assertSame(Command::SUCCESS, $create->execute(['login' => 'ada@example.com', '--name' => 'deploy', '--scope' => ['tasks:read', 'tasks:write'], '--expires' => '90 days']));
        $lines = explode("\n", trim($create->getDisplay()));
        self::assertMatchesRegularExpression('/\AToken (\d+) for user 1 on api, scopes tasks:read tasks:write, expires 2027-01-27 12:00 UTC:\z/', $lines[0]);
        self::assertMatchesRegularExpression('/\Axpsr_[0-9a-f]{64}\z/', $lines[1]);
        preg_match('/Token (\d+)/', $lines[0], $id);

        self::assertSame(Command::FAILURE, (new CommandTester($this->application->get(CreateTokenCommand::class)))->execute(['login' => 'nobody@example.com']));
        self::assertSame(Command::INVALID, (new CommandTester($this->application->get(CreateTokenCommand::class)))->execute(['login' => 'ada@example.com', '--expires' => 'whenever']));
        self::assertSame(Command::INVALID, (new CommandTester($this->application->get(CreateTokenCommand::class)))->execute(['login' => 'ada@example.com', '--scope' => ['has space']]));

        $revoke = new CommandTester($this->application->get(RevokeTokenCommand::class));
        self::assertSame(Command::SUCCESS, $revoke->execute(['id' => $id[1]]));
        self::assertSame("Revoked token {$id[1]}.\n", $revoke->getDisplay());
        self::assertSame(Command::FAILURE, $revoke->execute(['id' => $id[1]]));
        self::assertSame(Command::INVALID, $revoke->execute(['id' => 'abc']));
    }

    public function testATokenCountsOnlyOnItsGuard(): void
    {
        $tokens = $this->application->get(TokenRepository::class);
        $auth = $this->application->get(AuthManager::class);
        $ada = $auth->users()->findById(1) ?? self::fail();
        $partner = $tokens->create($ada, 'integration', guard: 'partner');
        self::assertSame('partner', $partner->token->guard);

        self::assertNull($auth->guard('api')->authenticate($this->bearer($partner->plainText)), 'Another guard\'s token is refused.');
        self::assertSame('partner', $auth->guard('partner')->authenticate($this->bearer($partner->plainText))?->guard);
        self::assertSame([], $tokens->forUser($ada), 'The api guard\'s list does not show it.');
        self::assertCount(1, $tokens->forUser($ada, 'partner'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The guard "web" does not use the token driver. Token guards: api, partner.');
        $tokens->create($ada, 'browser', guard: 'web');
    }

    private function bearer(string $token): ServerRequest
    {
        return new ServerRequest('GET', '/api/tasks', ['Authorization' => 'Bearer ' . $token]);
    }
}
