<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use DateInterval;
use DateTimeImmutable;
use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Key;
use InvalidArgumentException;

/**
 * The "oauth" configuration (config/oauth.php), validated:
 *
 *   private_key, public_key   the RSA key files that sign and verify access tokens, relative to the
 *                             project root (default var/oauth/private.key and var/oauth/public.key, or
 *                             OAUTH_PRIVATE_KEY and OAUTH_PUBLIC_KEY). php xpsr oauth:keys makes them.
 *   encryption_key            encrypts authorization codes and refresh tokens (OAUTH_ENCRYPTION_KEY;
 *                             oauth:keys makes one)
 *   scopes                    scope => the description the consent page shows, such as
 *                             'orders:read' => 'Read your orders'
 *   grants                    the grants to enable: client_credentials, authorization_code,
 *                             refresh_token (all three by default)
 *   access_token_ttl          how long an access token is valid (default "1 hour")
 *   refresh_token_ttl         how long a refresh token is valid (default "30 days")
 *   auth_code_ttl             how long an authorization code is valid (default "10 minutes")
 *   tables                    clients, access_tokens, refresh_tokens, auth_codes (default
 *                             oauth_clients, oauth_access_tokens, oauth_refresh_tokens, oauth_auth_codes)
 *   connection                the database connection, or null for the default
 *   view                      the consent page's view (default oauth.authorize, with a built-in page
 *                             when there is none); null for the built-in page only
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class OAuthOptions
{
    public const GRANTS = ['client_credentials', 'authorization_code', 'refresh_token'];
    public const DEFAULT_VIEW = 'oauth.authorize';
    /** A scope: printable text without spaces, quotes, or backslashes, as RequireScopes takes it. */
    public const SCOPE = '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/';
    private const KNOWN = ['private_key', 'public_key', 'encryption_key', 'scopes', 'grants', 'access_token_ttl', 'refresh_token_ttl', 'auth_code_ttl', 'tables', 'connection', 'view'];
    private const KEY_FILES = ['private_key' => ['OAUTH_PRIVATE_KEY', 'var/oauth/private.key'], 'public_key' => ['OAUTH_PUBLIC_KEY', 'var/oauth/public.key']];
    private const TABLES = ['clients' => 'oauth_clients', 'access_tokens' => 'oauth_access_tokens', 'refresh_tokens' => 'oauth_refresh_tokens', 'auth_codes' => 'oauth_auth_codes'];
    private const TTLS = ['access_token_ttl' => '1 hour', 'refresh_token_ttl' => '30 days', 'auth_code_ttl' => '10 minutes'];
    private const NAME = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /**
     * @param array<string, string> $scopes scope => description
     * @param list<string> $grants
     * @param array<string, string> $tables clients, access_tokens, refresh_tokens, auth_codes
     */
    private function __construct(
        public readonly string $privateKey,
        public readonly string $publicKey,
        private readonly ?string $encryptionKey,
        public readonly array $scopes,
        public readonly array $grants,
        public readonly int $accessTokenTtl,
        public readonly int $refreshTokenTtl,
        public readonly int $authCodeTtl,
        public readonly array $tables,
        public readonly ?string $connection,
        public readonly ?string $view,
    ) {}

    /**
     * @param array<array-key, mixed> $config the "oauth" configuration
     * @param array<string, mixed> $environment OAUTH_PRIVATE_KEY, OAUTH_PUBLIC_KEY, and OAUTH_ENCRYPTION_KEY, used when the configuration does not set the option
     */
    public static function from(array $config, array $environment = []): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option oauth.%s. Known options: %s.', $key, implode(', ', self::KNOWN)));
            }
        }
        $paths = [];
        foreach (self::KEY_FILES as $option => [$variable, $default]) {
            $path = $config[$option] ?? $environment[$variable] ?? $default;
            if (!is_string($path) || $path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
                throw new InvalidArgumentException("oauth.{$option} (or {$variable}) must be the path of a PEM file, relative to the project root, such as {$default}.");
            }
            $paths[$option] = $path;
        }
        $encryptionKey = $config['encryption_key'] ?? $environment['OAUTH_ENCRYPTION_KEY'] ?? null;
        if ($encryptionKey !== null && !is_string($encryptionKey)) {
            throw new InvalidArgumentException('oauth.encryption_key (or OAUTH_ENCRYPTION_KEY) must be the key php xpsr oauth:keys prints.');
        }
        $scopes = $config['scopes'] ?? [];
        if (!is_array($scopes)) {
            throw new InvalidArgumentException("oauth.scopes must map each scope to its description, such as 'orders:read' => 'Read your orders'.");
        }
        $descriptions = [];
        foreach ($scopes as $scope => $description) {
            if (!is_string($scope) || !preg_match(self::SCOPE, $scope) || !is_string($description)) {
                throw new InvalidArgumentException('oauth.scopes must map each scope (printable text without spaces, quotes, or backslashes, such as orders:read) to its description.');
            }
            $descriptions[$scope] = $description;
        }
        $grants = $config['grants'] ?? self::GRANTS;
        $known = is_array($grants) ? array_values(array_filter($grants, static fn(mixed $grant): bool => is_string($grant) && in_array($grant, self::GRANTS, true))) : [];
        if (!is_array($grants) || $grants === [] || count($known) !== count($grants) || count(array_unique($known)) !== count($known)) {
            throw new InvalidArgumentException('oauth.grants must list the grants to enable, from client_credentials, authorization_code, and refresh_token.');
        }
        $ttls = [];
        foreach (self::TTLS as $option => $default) {
            $period = $config[$option] ?? $default;
            $seconds = is_string($period) ? self::seconds($period) : 0;
            if ($seconds < 1) {
                throw new InvalidArgumentException("oauth.{$option} must be a period such as \"{$default}\".");
            }
            $ttls[$option] = $seconds;
        }
        $tables = $config['tables'] ?? [];
        if (!is_array($tables)) {
            throw new InvalidArgumentException('oauth.tables must map clients, access_tokens, refresh_tokens, and auth_codes to table names.');
        }
        foreach (array_keys($tables) as $key) {
            if (!isset(self::TABLES[$key])) {
                throw new InvalidArgumentException("Unknown option oauth.tables.{$key}. Known tables: " . implode(', ', array_keys(self::TABLES)) . '.');
            }
        }
        $names = [];
        foreach (self::TABLES as $key => $default) {
            $name = $tables[$key] ?? $default;
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException("oauth.tables.{$key} must be a plain table name: letters, digits, and underscores.");
            }
            $names[$key] = $name;
        }
        $connection = $config['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('oauth.connection must be a connection name from config/database.php, or null for the default connection.');
        }
        $view = array_key_exists('view', $config) ? $config['view'] : self::DEFAULT_VIEW;
        if ($view !== null && (!is_string($view) || $view === '')) {
            throw new InvalidArgumentException("oauth.view must name the consent page's view, such as oauth.authorize, or be null for the built-in page.");
        }

        return new self(
            $paths['private_key'],
            $paths['public_key'],
            $encryptionKey,
            $descriptions,
            $known,
            $ttls['access_token_ttl'],
            $ttls['refresh_token_ttl'],
            $ttls['auth_code_ttl'],
            $names,
            $connection,
            $view,
        );
    }

    /** The key that encrypts authorization codes and refresh tokens. */
    public function encryptionKey(): Key
    {
        if ($this->encryptionKey === null || $this->encryptionKey === '') {
            throw new InvalidArgumentException('oauth.encryption_key (or OAUTH_ENCRYPTION_KEY) is not set. Run php xpsr oauth:keys --write to make the keys and put it in .env.');
        }
        try {
            return Key::loadFromAsciiSafeString($this->encryptionKey);
        } catch (BadFormatException|EnvironmentIsBrokenException $exception) {
            throw new InvalidArgumentException('oauth.encryption_key (or OAUTH_ENCRYPTION_KEY) is not a key from php xpsr oauth:keys: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** Whether a scope is configured. */
    public function hasScope(string $scope): bool
    {
        return isset($this->scopes[$scope]);
    }

    /** The length of a period such as "1 hour" in seconds; 0 when it is not a period. */
    public static function seconds(string $period): int
    {
        try {
            return (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($period))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    public static function interval(int $seconds): DateInterval
    {
        return new DateInterval("PT{$seconds}S");
    }
}
