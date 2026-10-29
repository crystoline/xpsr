<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\GuardInterface;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Database\Auth\SanctumGuard;
use Crystoline\Xpsr\Database\Auth\SanctumTokenRepository;
use Crystoline\Xpsr\Database\Console\CreateTokenCommand;
use Crystoline\Xpsr\Database\Console\RevokeTokenCommand;
use Crystoline\Xpsr\Database\DatabaseServiceRegistry;
use Crystoline\Xpsr\Database\Tests\Fixtures\Workspace;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ContainerException;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The "sanctum" guard on a table with the columns of Laravel Sanctum 4's personal_access_tokens,
 * holding rows written as Sanctum writes them. Runs on the server in XPSR_TEST_PGSQL_URL or
 * XPSR_TEST_MYSQL_URL when set, otherwise on SQLite.
 */
final class SanctumTokenTest extends TestCase
{
    private const USER = 'App\Models\User';
    private const ADMIN = 'App\Models\Admin';

    private Workspace $workspace;
    private Application $application;
    private Connection $connection;
    private MockClock $clock;
    private string $suffix;
    private string $table;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
        mkdir($this->workspace->root . '/config');
        $this->suffix = bin2hex(random_bytes(3));
        $this->table = "personal_access_tokens_{$this->suffix}";
        $this->clock = new MockClock('2026-10-29 12:00:00', 'UTC');
        $this->boot();

        $schema = $this->connection->createSchemaManager();
        $schema->createTable(Table::editor()
            ->setUnquotedName("people_{$this->suffix}")
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('email')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('password')->setTypeName(Types::STRING)->setLength(255)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create());
        $this->connection->insert("people_{$this->suffix}", ['email' => 'ada@example.com', 'password' => 'x']);
        $this->connection->insert("people_{$this->suffix}", ['email' => 'grace@example.com', 'password' => 'x']);
        // As Sanctum 4's migration creates it.
        $time = static fn(string $name): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::DATETIME_MUTABLE)->setNotNull(false)->create();
        $schema->createTable(Table::editor()
            ->setUnquotedName($this->table)
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('tokenable_type')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('tokenable_id')->setTypeName(Types::BIGINT)->create(),
                Column::editor()->setUnquotedName('name')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('token')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('abilities')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
                $time('last_used_at'),
                $time('expires_at'),
                $time('created_at'),
                $time('updated_at'),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setIndexes(Index::editor()->setUnquotedName("pat_{$this->suffix}_token")->setType(IndexType::UNIQUE)->setUnquotedColumnNames('token')->create())
            ->create());
    }

    protected function tearDown(): void
    {
        $schema = $this->connection->createSchemaManager();
        $schema->dropTable($this->table);
        $schema->dropTable("people_{$this->suffix}");
        $this->connection->close();
        $this->workspace->remove();
    }

    public function testSanctumTokensSignInWithTheirAbilitiesAsScopes(): void
    {
        $token = $this->laravelToken(self::USER, 1, ['payments:create', 'payments:read']);
        $identity = $this->guard('api')->authenticate($this->bearer($token));

        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame(1, $identity->user->authIdentifier());
        self::assertSame('api', $identity->guard);
        self::assertSame(['payments:create', 'payments:read'], $identity->scopes());
        self::assertTrue($identity->hasScope('payments:read'));
        self::assertFalse($identity->hasScope('refunds:create'));
        self::assertSame(explode('|', $token)[0], $identity->tokenId);
        self::assertSame('2026-10-29 12:00:00', $this->row((int) $identity->tokenId)['last_used_at'], 'Last use is recorded.');

        $everything = $this->laravelToken(self::USER, 2);
        self::assertTrue($this->guard('api')->authenticate($this->bearer($everything))?->hasScope('refunds:create'), '["*"] allows everything.');
        $bare = explode('|', $everything, 2)[1];
        self::assertSame(2, $this->guard('api')->authenticate($this->bearer($bare))?->user->authIdentifier(), 'As Sanctum, a secret without its ID is looked up by hash.');
    }

    public function testWrongOrMalformedTokensAreRefused(): void
    {
        $token = $this->laravelToken(self::USER, 1);
        [$id, $secret] = explode('|', $token, 2);
        $guard = $this->guard('api');
        foreach (["{$id}|{$secret}x", ((int) $id + 1) . "|{$secret}", "x{$id}|{$secret}", "{$id}|", '|', 'nonsense', str_repeat('9', 300)] as $text) {
            self::assertNull($guard->authenticate($this->bearer($text)), $text);
        }
        self::assertSame('Bearer error="invalid_token"', $guard->challenge($this->bearer('nope')));
    }

    public function testEachGuardAcceptsOnlyTokensOfItsTokenableType(): void
    {
        $admin = $this->laravelToken(self::ADMIN, 1);
        $user = $this->laravelToken(self::USER, 1);

        self::assertNull($this->guard('api')->authenticate($this->bearer($admin)), 'An admin token is refused on the customers\' API.');
        self::assertSame('admin_api', $this->guard('admin_api')->authenticate($this->bearer($admin))?->guard);
        self::assertNull($this->guard('admin_api')->authenticate($this->bearer($user)));
    }

    public function testExpiredTokensAreRefused(): void
    {
        $expired = $this->laravelToken(self::USER, 1, expiresAt: '2026-10-29 11:59:59');
        $current = $this->laravelToken(self::USER, 1, expiresAt: '2026-10-29 12:00:01');
        self::assertNull($this->guard('api')->authenticate($this->bearer($expired)));
        self::assertNotNull($this->guard('api')->authenticate($this->bearer($current)));

        // auth.sanctum.expiration: a lifetime from created_at, in minutes as in Sanctum's config.
        $old = $this->laravelToken(self::USER, 1, createdAt: '2026-10-28 12:00:00');
        $recent = $this->laravelToken(self::USER, 1, createdAt: '2026-10-28 12:00:01');
        $this->boot(['expiration' => 24 * 60]);
        self::assertNull($this->guard('api')->authenticate($this->bearer($old)));
        self::assertNotNull($this->guard('api')->authenticate($this->bearer($recent)));
        $this->boot(['expiration' => '1 day']);
        self::assertNull($this->guard('api')->authenticate($this->bearer($old)), 'Or as a period.');
        $this->boot(['expiration' => '1440']);
        self::assertNull($this->guard('api')->authenticate($this->bearer($old)), 'Or as minutes from env().');
        self::assertNotNull($this->guard('api')->authenticate($this->bearer($recent)));
        $this->boot(['expiration' => '']);
        self::assertNotNull($this->guard('api')->authenticate($this->bearer($old)), 'An empty SANCTUM_EXPIRATION is no limit.');
    }

    public function testLastUseIsWrittenAtMostOnceAMinute(): void
    {
        $token = $this->laravelToken(self::USER, 1);
        $id = (int) explode('|', $token)[0];
        $this->guard('api')->authenticate($this->bearer($token));
        $this->clock->modify('+59 seconds');
        $this->guard('api')->authenticate($this->bearer($token));
        self::assertSame('2026-10-29 12:00:00', $this->row($id)['last_used_at']);
        $this->clock->modify('+2 seconds');
        $this->guard('api')->authenticate($this->bearer($token));
        self::assertSame('2026-10-29 12:01:01', $this->row($id)['last_used_at']);
        self::assertSame('2026-10-29 12:01:01', $this->row($id)['updated_at'], 'As Eloquent does when Sanctum saves the token.');
    }

    public function testNewTokensHaveSanctumsFormat(): void
    {
        $this->boot(['prefix' => 'mpay_']);
        $user = $this->application->get(AuthManager::class)->users()->findById(1) ?? self::fail();
        $new = $this->repository()->create($user, 'mobile app', ['payments:create'], new DateTimeImmutable('2027-01-01 00:00:00 UTC'));

        self::assertMatchesRegularExpression('/\A\d+\|mpay_[A-Za-z0-9]{40}[0-9a-f]{8}\z/', $new->plainText);
        [$id, $secret] = explode('|', $new->plainText, 2);
        self::assertSame(hash('crc32b', substr($secret, 5, 40)), substr($secret, 45), 'The checksum covers the 40 random characters, as in Sanctum.');
        $row = $this->row((int) $id);
        self::assertSame(hash('sha256', $secret), $row['token']);
        self::assertSame(self::USER, $row['tokenable_type']);
        self::assertSame(1, (int) $row['tokenable_id']);
        self::assertSame('mobile app', $row['name']);
        self::assertSame(['payments:create'], json_decode((string) $row['abilities'], true));
        self::assertSame('2027-01-01 00:00:00', $row['expires_at']);
        self::assertSame('2026-10-29 12:00:00', $row['created_at']);
        self::assertNull($row['last_used_at']);
        self::assertSame('api', $new->token->guard);
        self::assertSame(['payments:create'], $this->guard('api')->authenticate($this->bearer($new->plainText))?->scopes());

        self::assertSame([(int) $id], array_map(static fn($token): int => $token->id, $this->repository()->forUser($user)));
        self::assertSame(1, $this->repository()->revokeAll($user));
        self::assertNull($this->guard('api')->authenticate($this->bearer($new->plainText)));
    }

    public function testTimesAreInTheApplicationsTimeZone(): void
    {
        $this->clock = new MockClock('2026-10-29 12:00:00', 'Africa/Lagos');
        $this->boot();
        $user = $this->application->get(AuthManager::class)->users()->findById(1) ?? self::fail();
        $new = $this->repository()->create($user, 'mobile app', expiresAt: new DateTimeImmutable('2026-10-29 12:30:00 UTC'));

        $row = $this->row($new->token->id);
        self::assertSame('2026-10-29 12:00:00', $row['created_at'], 'Lagos time, as Laravel stores it with app.timezone Africa/Lagos.');
        self::assertSame('2026-10-29 13:30:00', $row['expires_at']);
        $this->clock->modify('+89 minutes');
        self::assertNotNull($this->guard('api')->authenticate($this->bearer($new->plainText)));
        $this->clock->modify('+2 minutes');
        self::assertNull($this->guard('api')->authenticate($this->bearer($new->plainText)), 'Expired at 13:30 Lagos, 12:30 UTC.');
    }

    public function testTheTokenCommandsWorkOnSanctumGuards(): void
    {
        $create = new CommandTester($this->application->get(CreateTokenCommand::class));
        self::assertSame(Command::SUCCESS, $create->execute(['login' => 'grace@example.com', '--scope' => ['payments:read']]));
        [$summary, $plain] = explode("\n", trim($create->getDisplay()));
        self::assertMatchesRegularExpression('/\AToken (\d+) for user 2 on api, scopes payments:read, until revoked:\z/', $summary, 'The default guard comes first.');
        self::assertMatchesRegularExpression('/\A\d+\|[A-Za-z0-9]{48}\z/', $plain);
        self::assertSame(2, $this->guard('api')->authenticate($this->bearer($plain))?->user->authIdentifier());

        $revoke = new CommandTester($this->application->get(RevokeTokenCommand::class));
        $id = explode('|', $plain)[0];
        self::assertSame(Command::SUCCESS, $revoke->execute(['id' => $id, '--guard' => 'api']));
        self::assertSame("Revoked token {$id}.\n", $revoke->getDisplay());
        self::assertNull($this->guard('api')->authenticate($this->bearer($plain)));
        self::assertSame(Command::FAILURE, $revoke->execute(['id' => $id]));

        $web = new CommandTester($this->application->get(CreateTokenCommand::class));
        self::assertSame(Command::INVALID, $web->execute(['login' => 'ada@example.com', '--guard' => 'web']));
        self::assertStringContainsString('The guard "web" does not use the token or sanctum driver.', (string) preg_replace('/\s+/', ' ', $web->getDisplay()));
    }

    public function testAGuardWithoutATokenableTypeSaysWhatToSet(): void
    {
        $this->boot(guards: ['api' => ['driver' => 'sanctum']]);
        try {
            $this->guard('api');
            self::fail('A sanctum guard was made without a tokenable type.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('auth.guards.api.tokenable_type must name what Laravel stored in personal_access_tokens.tokenable_type, such as App\Models\User.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['token_prefix' => 'x'], 'Unknown option auth.sanctum.token_prefix. Known options: table, connection, expiration, prefix.'];
        yield 'zero minutes' => [['expiration' => 0], 'auth.sanctum.expiration must be minutes'];
        yield 'nonsense period' => [['expiration' => 'soon'], 'auth.sanctum.expiration must be minutes'];
        yield 'table' => [['table' => 'tokens; DROP TABLE users'], 'auth.sanctum.table must be a plain table name'];
    }

    /** @param array<string, mixed> $sanctum */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function testInvalidOptionsNameTheSetting(array $sanctum, string $message): void
    {
        $this->boot($sanctum, table: false);
        try {
            $this->application->get(SanctumTokenRepository::class);
            self::fail('Sanctum tokens were set up from invalid options.');
        } catch (ContainerException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $sanctum
     * @param array<string, array<string, mixed>>|null $guards
     */
    private function boot(array $sanctum = [], ?array $guards = null, bool $table = true): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        $url = (string) (getenv('XPSR_TEST_PGSQL_URL') ?: getenv('XPSR_TEST_MYSQL_URL'));
        $this->application = new Application($this->workspace->root);
        $config = $this->application->getConfig();
        $config->set('app.registry', [ConsoleServiceRegistry::class, DatabaseServiceRegistry::class, AuthServiceRegistry::class]);
        $config->set('database', [
            'default' => 'test',
            'connections' => ['test' => $url !== '' ? ['url' => $url] : ['driver' => 'pdo_sqlite', 'path' => 'var/sanctum.sqlite']],
        ]);
        $users = static fn(string $table): array => ['driver' => 'database', 'table' => $table, 'login' => 'email', 'password' => 'password'];
        $config->set('auth', [
            'default' => 'api',
            'guards' => $guards ?? [
                'web' => ['driver' => 'session', 'provider' => 'users'],
                'api' => ['driver' => 'sanctum', 'provider' => 'users', 'tokenable_type' => self::USER],
                'admin_api' => ['driver' => 'sanctum', 'provider' => 'admins', 'tokenable_type' => self::ADMIN],
            ],
            'providers' => ['users' => $users("people_{$this->suffix}"), 'admins' => $users("people_{$this->suffix}")],
            'sanctum' => $sanctum + ($table ? ['table' => $this->table] : []),
        ]);
        $this->application->getContainer()->instance(ClockInterface::class, $this->clock);
        $this->application->boot();
        $this->connection = $this->application->get(Connection::class);
    }

    private function guard(string $name): GuardInterface
    {
        $guard = $this->application->get(AuthManager::class)->guard($name);
        self::assertInstanceOf(SanctumGuard::class, $guard);

        return $guard;
    }

    private function repository(): SanctumTokenRepository
    {
        $tokens = $this->application->get(SanctumTokenRepository::class);
        self::assertInstanceOf(SanctumTokenRepository::class, $tokens);

        return $tokens;
    }

    /**
     * A token as Laravel Sanctum creates it: 40 random characters and their CRC32, stored as
     * SHA-256, and given to the client as "<id>|<secret>".
     *
     * @param list<string> $abilities
     */
    private function laravelToken(string $type, int $userId, array $abilities = ['*'], ?string $expiresAt = null, string $createdAt = '2026-10-29 11:00:00'): string
    {
        $entropy = substr(str_shuffle(str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 2)), 0, 40);
        $secret = $entropy . hash('crc32b', $entropy);
        $this->connection->insert($this->table, [
            'tokenable_type' => $type,
            'tokenable_id' => $userId,
            'name' => 'mobile',
            'token' => hash('sha256', $secret),
            'abilities' => json_encode($abilities),
            'last_used_at' => null,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $this->connection->lastInsertId() . '|' . $secret;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
        self::assertIsArray($row);
        $row = array_change_key_case($row, CASE_LOWER);
        // Servers return times as "Y-m-d H:i:s", some with fractions; compare to the second.
        foreach (['last_used_at', 'expires_at', 'created_at', 'updated_at'] as $column) {
            if (is_string($row[$column])) {
                $row[$column] = substr($row[$column], 0, 19);
            }
        }

        return $row;
    }

    private function bearer(string $token): ServerRequest
    {
        return new ServerRequest('GET', '/api/payments', ['Authorization' => 'Bearer ' . $token]);
    }
}
