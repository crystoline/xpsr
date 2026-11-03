<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests;

use Crystoline\Xpsr\Auth\OAuth2\OAuthOptions;
use Defuse\Crypto\Key;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OAuthOptionsTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $options = OAuthOptions::from([]);
        self::assertSame(['var/oauth/private.key', 'var/oauth/public.key'], [$options->privateKey, $options->publicKey]);
        self::assertSame([], $options->scopes);
        self::assertSame(['client_credentials', 'authorization_code', 'refresh_token'], $options->grants);
        self::assertSame([3600, 30 * 86400, 600], [$options->accessTokenTtl, $options->refreshTokenTtl, $options->authCodeTtl]);
        self::assertSame(['clients' => 'oauth_clients', 'access_tokens' => 'oauth_access_tokens', 'refresh_tokens' => 'oauth_refresh_tokens', 'auth_codes' => 'oauth_auth_codes'], $options->tables);
        self::assertNull($options->connection);
        self::assertSame('oauth.authorize', $options->view);
        self::assertSame('PT90S', OAuthOptions::interval(90)->format('PT%sS'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('oauth.encryption_key (or OAUTH_ENCRYPTION_KEY) is not set. Run php xpsr oauth:keys --write');
        $options->encryptionKey();
    }

    public function testTheEnvironmentFillsInWhatTheConfigurationLeavesOut(): void
    {
        $key = Key::createNewRandomKey()->saveToAsciiSafeString();
        $options = OAuthOptions::from(
            ['public_key' => 'keys/public.pem', 'scopes' => ['orders:read' => 'Read your orders'], 'grants' => ['client_credentials'], 'access_token_ttl' => '15 minutes', 'tables' => ['clients' => 'apps'], 'connection' => 'auth', 'view' => null],
            ['OAUTH_PRIVATE_KEY' => 'keys/private.pem', 'OAUTH_PUBLIC_KEY' => 'ignored.pem', 'OAUTH_ENCRYPTION_KEY' => $key],
        );
        self::assertSame(['keys/private.pem', 'keys/public.pem'], [$options->privateKey, $options->publicKey], 'The configuration wins over the environment.');
        self::assertSame($key, $options->encryptionKey()->saveToAsciiSafeString());
        self::assertSame(['orders:read' => 'Read your orders'], $options->scopes);
        self::assertTrue($options->hasScope('orders:read'));
        self::assertFalse($options->hasScope('orders:write'));
        self::assertSame(['client_credentials'], $options->grants);
        self::assertSame(900, $options->accessTokenTtl);
        self::assertSame('apps', $options->tables['clients']);
        self::assertSame('oauth_access_tokens', $options->tables['access_tokens']);
        self::assertSame('auth', $options->connection);
        self::assertNull($options->view);
    }

    public function testAKeyThatIsNotFromTheCommandIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('oauth.encryption_key (or OAUTH_ENCRYPTION_KEY) is not a key from php xpsr oauth:keys');
        OAuthOptions::from(['encryption_key' => 'not a defuse key'])->encryptionKey();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'an unknown option' => [['ttl' => '1 hour'], 'Unknown option oauth.ttl. Known options: private_key, public_key, encryption_key, scopes'];
        yield 'a key path with a control character' => [['private_key' => "var/\n.key"], 'oauth.private_key (or OAUTH_PRIVATE_KEY) must be the path of a PEM file'];
        yield 'a scope with a space' => [['scopes' => ['read orders' => 'Read']], 'oauth.scopes must map each scope (printable text without spaces, quotes, or backslashes, such as orders:read) to its description.'];
        yield 'a scope without a description' => [['scopes' => ['orders:read']], 'oauth.scopes must map each scope'];
        yield 'scopes that are not a map' => [['scopes' => 'orders:read'], "oauth.scopes must map each scope to its description, such as 'orders:read' => 'Read your orders'."];
        yield 'an unknown grant' => [['grants' => ['password']], 'oauth.grants must list the grants to enable, from client_credentials, authorization_code, and refresh_token.'];
        yield 'no grants' => [['grants' => []], 'oauth.grants must list the grants to enable'];
        yield 'a grant twice' => [['grants' => ['refresh_token', 'refresh_token']], 'oauth.grants must list the grants to enable'];
        yield 'a lifetime that is not a period' => [['refresh_token_ttl' => 'soon'], 'oauth.refresh_token_ttl must be a period such as "30 days".'];
        yield 'a lifetime of zero' => [['auth_code_ttl' => '0 seconds'], 'oauth.auth_code_ttl must be a period such as "10 minutes".'];
        yield 'an unknown table' => [['tables' => ['users' => 'users']], 'Unknown option oauth.tables.users. Known tables: clients, access_tokens, refresh_tokens, auth_codes.'];
        yield 'a table name with a dash' => [['tables' => ['auth_codes' => 'auth-codes']], 'oauth.tables.auth_codes must be a plain table name: letters, digits, and underscores.'];
        yield 'an empty connection name' => [['connection' => ''], 'oauth.connection must be a connection name from config/database.php, or null for the default connection.'];
        yield 'an empty view name' => [['view' => ''], "oauth.view must name the consent page's view, such as oauth.authorize, or be null for the built-in page."];
        yield 'an encryption key that is not text' => [['encryption_key' => 42], 'oauth.encryption_key (or OAUTH_ENCRYPTION_KEY) must be the key php xpsr oauth:keys prints.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationIsNamed(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        OAuthOptions::from($config);
    }
}
