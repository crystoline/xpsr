<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\GenericUser;
use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\InMemoryUserProvider;
use Crystoline\Xpsr\Auth\Jwt\Console\IssueJwtCommand;
use Crystoline\Xpsr\Auth\Jwt\Console\JwtSecretCommand;
use Crystoline\Xpsr\Auth\Jwt\JwtGuard;
use Crystoline\Xpsr\Auth\Jwt\JwtIssuer;
use Crystoline\Xpsr\Auth\Jwt\JwtOptions;
use Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry;
use Crystoline\Xpsr\Auth\PasswordHasher;
use Crystoline\Xpsr\Registry\AuthServiceRegistry;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class JwtTest extends TestCase
{
    private const SECRET = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWYwMTIzNDU2Nzg5YWJjZGVmMDEyMzQ1Njc4OWFiY2RlZg==';

    private string $root;
    private MockClock $clock;
    private InMemoryUserProvider $users;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/xpsr jwt ' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        $this->clock = new MockClock('2026-10-29 12:00:00');
        $this->users = new InMemoryUserProvider();
        $this->users->add(new GenericUser(7, (new PasswordHasher())->hash('secret'), ['email' => 'ada@example.com']), login: 'ada@example.com');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (['config', 'keys'] as $directory) {
            if (is_dir("{$this->root}/{$directory}")) {
                array_map(unlink(...), glob("{$this->root}/{$directory}/*") ?: []);
                rmdir("{$this->root}/{$directory}");
            }
        }
        rmdir($this->root);
    }

    public function testIssuedTokensAuthenticateTheirUserWithTheirScopes(): void
    {
        $app = $this->app();
        $issued = $app->get(JwtIssuer::class)->issue($this->users->findById(7) ?? self::fail(), ['tasks:read', 'tasks:write']);
        self::assertSame('2026-10-29 13:00:00', $issued->expiresAt->format('Y-m-d H:i:s'), 'auth.jwt.ttl is 1 hour by default.');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $issued->id);

        $guard = $app->get(AuthManager::class)->guard('api');
        self::assertInstanceOf(JwtGuard::class, $guard, 'The api guard uses the jwt driver.');
        $identity = $guard->authenticate($this->bearer($issued->token));
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame([7, 'api', $issued->id], [$identity->user->authIdentifier(), $identity->guard, $identity->tokenId]);
        self::assertSame(['tasks:read', 'tasks:write'], $identity->scopes());

        $claims = json_decode((string) base64_decode(strtr(explode('.', $issued->token)[1], '-_', '+/')), true);
        self::assertSame(['https://app.example.com', 'https://app.example.com', '7', 'tasks:read tasks:write'], [$claims['iss'], $claims['aud'], $claims['sub'], $claims['scope']]);
    }

    public function testTokensExpireAfterTheirLifetimeAndTheLeeway(): void
    {
        $app = $this->app();
        $token = $app->get(JwtIssuer::class)->issue($this->users->findById(7) ?? self::fail(), ttl: '15 minutes')->token;
        $guard = $app->get(AuthManager::class)->guard('api');

        $this->clock->modify('+15 minutes +29 seconds');
        self::assertNotNull($guard->authenticate($this->bearer($token)), 'Within the 30 seconds of leeway.');
        $this->clock->modify('+2 seconds');
        self::assertNull($guard->authenticate($this->bearer($token)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function otherSigners(): iterable
    {
        yield 'another secret' => [['secret' => 'base64:' . base64_encode(str_repeat('x', 64))]];
        yield 'another issuer' => [['issuer' => 'https://other.example.com']];
        yield 'another audience' => [['audience' => 'https://other.example.com']];
    }

    /** @param array<string, mixed> $jwt */
    #[DataProvider('otherSigners')]
    public function testTokensFromOtherIssuersOrSecretsAreRefused(array $jwt): void
    {
        $user = $this->users->findById(7) ?? self::fail();
        $token = $this->app($jwt)->get(JwtIssuer::class)->issue($user)->token;
        self::assertNull($this->app()->get(AuthManager::class)->guard('api')->authenticate($this->bearer($token)));
    }

    public function testTamperedUnsignedAndMalformedTokensAreRefused(): void
    {
        $app = $this->app();
        $guard = $app->get(AuthManager::class)->guard('api');
        $token = $app->get(JwtIssuer::class)->issue($this->users->findById(7) ?? self::fail())->token;
        [$header, $payload, $signature] = explode('.', $token);
        $encode = static fn(array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        $forged = $encode(array_merge($claims, ['sub' => '1']));
        foreach ([
            "{$header}.{$forged}.{$signature}",
            $encode(['alg' => 'none', 'typ' => 'JWT']) . ".{$payload}.",
            $encode(['alg' => 'HS512', 'typ' => 'JWT']) . ".{$payload}.{$signature}",
            'not-a-token',
            'a.b.c',
        ] as $attempt) {
            self::assertNull($guard->authenticate($this->bearer($attempt)), $attempt);
        }
        self::assertSame('Bearer error="invalid_token"', $guard->challenge($this->bearer('x')));
        self::assertSame('Bearer', $guard->challenge(new ServerRequest('GET', '/')));
    }

    public function testADeletedUsersTokensStopWorking(): void
    {
        $app = $this->app();
        $token = $app->get(JwtIssuer::class)->issue(new GenericUser(99, null))->token;
        self::assertNull($app->get(AuthManager::class)->guard('api')->authenticate($this->bearer($token)));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function keyPairs(): iterable
    {
        yield 'RS256' => ['RS256', ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]];
        yield 'ES256' => ['ES256', ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('keyPairs')]
    public function testKeyPairsSignAndVerify(string $algorithm, array $options): void
    {
        mkdir($this->root . '/keys');
        $key = openssl_pkey_new($options) ?: self::fail('openssl could not make a key.');
        openssl_pkey_export($key, $private);
        file_put_contents($this->root . '/keys/private.pem', $private);
        file_put_contents($this->root . '/keys/public.pem', openssl_pkey_get_details($key)['key'] ?? '');
        $app = $this->app(['algorithm' => $algorithm, 'private_key' => 'keys/private.pem', 'public_key' => 'keys/public.pem', 'secret' => null]);

        $token = $app->get(JwtIssuer::class)->issue($this->users->findById(7) ?? self::fail())->token;
        self::assertStringStartsWith(rtrim(strtr(base64_encode('{"typ":"JWT","alg":"' . $algorithm . '"}'), '+/', '-_'), '='), $token);
        self::assertSame(7, $app->get(AuthManager::class)->guard('api')->authenticate($this->bearer($token))?->user->authIdentifier());
        self::assertNull($this->app()->get(AuthManager::class)->guard('api')->authenticate($this->bearer($token)), 'An HS256 configuration refuses it.');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'no secret' => [['secret' => null], 'auth.jwt.secret is not set. Run php xpsr auth:jwt-secret --write'];
        yield 'short secret' => [['secret' => 'too short'], 'auth.jwt.secret must be at least 32 bytes for HS256'];
        yield 'short for HS512' => [['algorithm' => 'HS512', 'secret' => str_repeat('x', 48)], 'at least 64 bytes for HS512'];
        yield 'bad base64' => [['secret' => 'base64:***'], 'the rest is not base64'];
        yield 'unknown algorithm' => [['algorithm' => 'none'], 'auth.jwt.algorithm must be HS256, HS384, HS512, RS256, or ES256.'];
        yield 'missing key file' => [['algorithm' => 'RS256', 'private_key' => 'keys/missing.pem', 'public_key' => 'keys/missing.pem'], 'auth.jwt.private_key: the key file keys/missing.pem does not exist'];
        yield 'unknown option' => [['expires' => '1 hour'], 'Unknown option auth.jwt.expires.'];
        yield 'bad ttl' => [['ttl' => 3600], 'auth.jwt.ttl must be a period'];
        yield 'bad leeway' => [['leeway' => 3600], 'auth.jwt.leeway must be 0 to 300 seconds.'];
    }

    /** @param array<string, mixed> $jwt */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreExplained(array $jwt, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        JwtOptions::fromArray(array_filter($jwt + ['secret' => self::SECRET], static fn($value) => $value !== null), $this->root, 'https://app.example.com');
    }

    public function testTheSecretCommandPrintsOrWritesASecret(): void
    {
        $app = $this->app();
        $printed = new CommandTester($app->get(JwtSecretCommand::class));
        self::assertSame(Command::SUCCESS, $printed->execute([]));
        self::assertMatchesRegularExpression('/\Abase64:[A-Za-z0-9+\/]{86}==\n\z/', $printed->getDisplay());
        self::assertSame(64, strlen((string) base64_decode(substr(trim($printed->getDisplay()), 7))));

        file_put_contents($this->root . '/.env', "APP_NAME=Test\nAUTH_JWT_SECRET=\n");
        $write = new CommandTester($app->get(JwtSecretCommand::class));
        self::assertSame(Command::SUCCESS, $write->execute(['--write' => true]));
        $env = (string) file_get_contents($this->root . '/.env');
        self::assertMatchesRegularExpression('/\AAPP_NAME=Test\nAUTH_JWT_SECRET=base64:\S{88}\n\z/', $env);

        self::assertSame(Command::FAILURE, $write->execute(['--write' => true]), 'A set secret is kept.');
        self::assertSame($env, file_get_contents($this->root . '/.env'));
        self::assertSame(Command::SUCCESS, $write->execute(['--write' => true, '--force' => true]));
        self::assertNotSame($env, file_get_contents($this->root . '/.env'));

        unlink($this->root . '/.env');
        self::assertSame(Command::SUCCESS, $write->execute(['--write' => true]));
        self::assertMatchesRegularExpression('/\AAUTH_JWT_SECRET=base64:\S{88}\n\z/', (string) file_get_contents($this->root . '/.env'));
    }

    public function testTheIssueCommandPrintsAToken(): void
    {
        $app = $this->app();
        $tester = new CommandTester($app->get(IssueJwtCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['login' => 'ada@example.com', '--scope' => ['tasks:read'], '--ttl' => '5 minutes']));
        [$token, $note] = explode("\n", trim($tester->getDisplay()));
        self::assertSame('Valid until 2026-10-29 12:05 UTC. Send it as: Authorization: Bearer <token>', $note);
        $identity = $app->get(AuthManager::class)->guard('api')->authenticate($this->bearer($token));
        self::assertSame(['tasks:read'], $identity?->scopes());
        self::assertSame(Command::FAILURE, $tester->execute(['login' => 'nobody@example.com']));
        self::assertSame(Command::INVALID, $tester->execute(['login' => 'ada@example.com', '--ttl' => 'soon']));
    }

    public function testATokenCountsOnlyOnTheGuardItWasIssuedFor(): void
    {
        $app = $this->app();
        $auth = $app->get(AuthManager::class);
        $admin = $auth->users('admins')->findById(7) ?? self::fail();
        $issued = $app->get(JwtIssuer::class)->issue($admin, guard: 'admin-api');
        self::assertSame('admin-api', $issued->guard);

        self::assertNull($auth->guard('api')->authenticate($this->bearer($issued->token)), 'User 7 and admin 7 are different people.');
        $identity = $auth->guard('admin-api')->authenticate($this->bearer($issued->token));
        self::assertSame(['admin-api', 'admin'], [$identity?->guard, $identity?->user instanceof GenericUser ? $identity->user->get('role') : null]);
        self::assertSame('api', $app->get(JwtIssuer::class)->issue($this->users->findById(7) ?? self::fail())->guard, 'The first jwt guard is the default.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The guard "web" does not use the jwt driver. JWT guards: api, admin-api.');
        $app->get(JwtIssuer::class)->issue($admin, guard: 'web');
    }

    /** @param array<string, mixed> $jwt */
    private function app(array $jwt = []): Application
    {
        $app = new Application($this->root);
        $config = $app->getConfig();
        $config->set('app.url', 'https://app.example.com/');
        $config->set('app.registry', [ConsoleServiceRegistry::class, AuthServiceRegistry::class, JwtServiceRegistry::class]);
        $config->set('auth', [
            'guards' => ['web' => ['driver' => 'session'], 'api' => ['driver' => 'jwt'], 'admin-api' => ['driver' => 'jwt', 'provider' => 'admins']],
            'providers' => ['users' => ['driver' => 'test.users'], 'admins' => ['driver' => 'test.admins']],
            'jwt' => array_filter($jwt + ['secret' => self::SECRET], static fn($value) => $value !== null),
        ]);
        $app->getContainer()->instance('test.users', $this->users);
        $admins = new InMemoryUserProvider();
        $admins->add(new GenericUser(7, null, ['role' => 'admin']), login: 'root@example.com');
        $app->getContainer()->instance('test.admins', $admins);
        $app->getContainer()->instance(ClockInterface::class, $this->clock);
        $app->boot();

        return $app;
    }

    private function bearer(string $token): ServerRequest
    {
        return new ServerRequest('GET', '/api/tasks', ['Authorization' => 'Bearer ' . $token]);
    }
}
