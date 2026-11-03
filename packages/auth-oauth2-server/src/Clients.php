<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use JsonException;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The applications registered with the server, in the clients table php xpsr make:oauth-tables
 * creates. A secret is random (64 hexadecimal characters) and stored only as a SHA-256 hash, so a
 * copy of the table cannot be used to get tokens.
 *
 *     $new = $clients->create('Reporting service');                                         // client_credentials
 *     $new = $clients->create('Mobile app', ['https://app.example/callback'], confidential: false);
 *     $new->secret;                        // show it once; null for a public client
 *     $clients->revoke($new->client->id);  // and every token it holds
 */
final class Clients implements ClientRepositoryInterface
{
    private readonly string $table;

    public function __construct(
        private readonly Connection $connection,
        private readonly OAuthOptions $options,
        private readonly ClockInterface $clock,
        private readonly Tokens $tokens,
    ) {
        $this->table = $options->tables['clients'];
    }

    /**
     * Register a client and return it with its secret, shown only now.
     *
     * @param list<string> $redirectUris where the authorization code grant may send browsers back to
     * @param list<string>|null $grants the grants it may use; by default authorization_code and refresh_token with redirect URIs, client_credentials without
     * @param list<string>|null $scopes the scopes it may ask for, from oauth.scopes; null for all of them
     * @param bool $confidential false for a mobile or browser application, which gets no secret and must use PKCE
     */
    public function create(string $name, array $redirectUris = [], ?array $grants = null, ?array $scopes = null, bool $confidential = true): NewClient
    {
        if (trim($name) === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A client name must be 1 to 255 characters, such as "Mobile app".');
        }
        foreach ($redirectUris as $uri) {
            self::checkRedirectUri($uri);
        }
        $grants ??= $redirectUris === [] ? ['client_credentials'] : ['authorization_code', 'refresh_token'];
        $known = array_values(array_filter($grants, static fn(string $grant): bool => in_array($grant, OAuthOptions::GRANTS, true)));
        if ($grants === [] || count($known) !== count($grants)) {
            throw new InvalidArgumentException('Grants must be client_credentials, authorization_code, or refresh_token.');
        }
        $known = array_values(array_unique($known));
        if (!$confidential && in_array('client_credentials', $known, true)) {
            throw new InvalidArgumentException('A public client cannot use the client_credentials grant: it has no secret to prove itself with.');
        }
        if (in_array('authorization_code', $known, true) && $redirectUris === []) {
            throw new InvalidArgumentException('The authorization_code grant needs at least one redirect URI.');
        }
        if ($scopes !== null) {
            foreach ($scopes as $scope) {
                if (!$this->options->hasScope($scope)) {
                    throw new InvalidArgumentException(sprintf('The scope "%s" is not in oauth.scopes. Configured scopes: %s.', $scope, implode(', ', array_keys($this->options->scopes)) ?: 'none'));
                }
            }
            $scopes = array_values(array_unique($scopes));
        }
        $id = bin2hex(random_bytes(16));
        $secret = $confidential ? bin2hex(random_bytes(32)) : null;
        $now = $this->clock->now()->getTimestamp();
        $this->connection->insert($this->table, [
            'id' => $id,
            'name' => $name,
            'secret_hash' => $secret === null ? null : hash('sha256', $secret),
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'grants' => json_encode($known, JSON_THROW_ON_ERROR),
            'scopes' => $scopes === null ? null : json_encode($scopes, JSON_THROW_ON_ERROR),
            'confidential' => $confidential,
            'created_at' => $now,
            'revoked_at' => null,
        ], ['confidential' => ParameterType::BOOLEAN]);

        return new NewClient($secret, new Client($id, $name, $redirectUris, $known, $scopes, $confidential, self::time($now)));
    }

    /** A client by ID, revoked or not. */
    public function find(string $id): ?Client
    {
        $row = $this->row($id);

        return $row === null ? null : self::client($row);
    }

    /** @return list<Client> every client, oldest first, revoked ones included */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM {$this->table} ORDER BY created_at, id");

        return array_values(array_filter(array_map(self::client(...), $rows)));
    }

    /** Revoke a client and every token it holds; false when there is no such client or it was revoked already. */
    public function revoke(string $id): bool
    {
        $revoked = $this->connection->executeStatement(
            "UPDATE {$this->table} SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL",
            [$this->clock->now()->getTimestamp(), $id],
        ) > 0;
        if ($revoked) {
            $this->tokens->revokeForClient($id);
        }

        return $revoked;
    }

    /** @internal The server's view: a client by ID, unless revoked. */
    public function getClientEntity(mixed $clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->find((string) $clientIdentifier);

        return $client === null || $client->isRevoked() ? null : $client;
    }

    /**
     * @internal The server's check of a client's secret and grant. A public client has no secret
     * and proves itself with PKCE instead.
     */
    public function validateClient(mixed $clientIdentifier, mixed $clientSecret, mixed $grantType): bool
    {
        $row = $this->row((string) $clientIdentifier);
        if ($row === null) {
            return false;
        }
        $client = self::client($row);
        if ($client === null || $client->isRevoked() || (is_string($grantType) && !$client->supportsGrantType($grantType))) {
            return false;
        }
        if (!$client->confidential) {
            return true;
        }
        $hash = $row['secret_hash'];

        return is_string($hash) && is_string($clientSecret) && $clientSecret !== '' && hash_equals($hash, hash('sha256', $clientSecret));
    }

    /** @return array<string, mixed>|null */
    private function row(string $id): ?array
    {
        if ($id === '' || strlen($id) > 64) {
            return null;
        }
        $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [$id]);

        return $row === false ? null : array_change_key_case($row, CASE_LOWER);
    }

    /** @param array<string, mixed> $row */
    private static function client(array $row): ?Client
    {
        $row = array_change_key_case($row, CASE_LOWER);
        try {
            $uris = json_decode((string) $row['redirect_uris'], true, 4, JSON_THROW_ON_ERROR);
            $grants = json_decode((string) $row['grants'], true, 4, JSON_THROW_ON_ERROR);
            $scopes = $row['scopes'] === null ? null : json_decode((string) $row['scopes'], true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($uris) || !is_array($grants) || ($scopes !== null && !is_array($scopes))) {
            return null;
        }

        return new Client(
            (string) $row['id'],
            (string) $row['name'],
            self::strings($uris),
            self::strings($grants),
            $scopes === null ? null : self::strings($scopes),
            (bool) $row['confidential'],
            self::time((int) $row['created_at']),
            $row['revoked_at'] === null ? null : self::time((int) $row['revoked_at']),
        );
    }

    private static function checkRedirectUri(string $uri): void
    {
        $parts = $uri === '' || strlen($uri) > 2048 || preg_match('/[\x00-\x20\x7F#]/', $uri) ? false : parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("A redirect URI must be an absolute URL without a fragment, such as https://app.example/callback or myapp://callback, not \"{$uri}\".");
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    private static function time(int $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $timestamp)->setTimezone(new DateTimeZone('UTC'));
    }
}
