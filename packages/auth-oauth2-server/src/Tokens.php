<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Crystoline\Xpsr\Auth\OAuth2\Entity\AccessToken;
use Crystoline\Xpsr\Auth\OAuth2\Entity\AuthCode;
use Crystoline\Xpsr\Auth\OAuth2\Entity\RefreshToken;
use Crystoline\Xpsr\Auth\UserInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use JsonException;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The access tokens, refresh tokens, and authorization codes the server issues, in the tables
 * php xpsr make:oauth-tables creates. An access token is a signed JWT that proves itself; its
 * row records what was issued, so that it can be listed and revoked. Revoking an access token
 * revokes its refresh tokens.
 *
 *     $tokens->forUser($user);               // the applications a user connected
 *     $tokens->revoke($identity->tokenId);   // end one token
 *     $tokens->revokeForUser($user);         // disconnect every application
 *     $tokens->purge();                      // remove expired and revoked rows (php xpsr oauth:purge)
 */
final class Tokens implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface
{
    /** @var array<string, string> */
    private readonly array $tables;

    public function __construct(private readonly Connection $connection, OAuthOptions $options, private readonly ClockInterface $clock)
    {
        $this->tables = $options->tables;
    }

    /** @return list<IssuedToken> a user's access tokens that are still valid, newest first */
    public function forUser(UserInterface $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.id, t.client_id, c.name AS client_name, t.user_id, t.scopes, t.created_at, t.expires_at"
            . " FROM {$this->tables['access_tokens']} t JOIN {$this->tables['clients']} c ON c.id = t.client_id"
            . ' WHERE t.user_id = ? AND t.revoked = ? AND t.expires_at > ? ORDER BY t.created_at DESC, t.id',
            [(string) $user->authIdentifier(), false, $this->now()],
            [ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::INTEGER],
        );

        return array_values(array_filter(array_map(self::issued(...), $rows)));
    }

    /** Revoke an access token and its refresh tokens; false when there was no such token or it was revoked already. */
    public function revoke(string $tokenId): bool
    {
        $revoked = $this->connection->executeStatement(
            "UPDATE {$this->tables['access_tokens']} SET revoked = ? WHERE id = ? AND revoked = ?",
            [true, $tokenId, false],
            [ParameterType::BOOLEAN, ParameterType::STRING, ParameterType::BOOLEAN],
        ) > 0;
        $this->connection->executeStatement(
            "UPDATE {$this->tables['refresh_tokens']} SET revoked = ? WHERE access_token_id = ?",
            [true, $tokenId],
            [ParameterType::BOOLEAN, ParameterType::STRING],
        );

        return $revoked;
    }

    /** Revoke every token of a user, for one client or for all, and return how many access tokens there were. */
    public function revokeForUser(UserInterface $user, ?string $clientId = null): int
    {
        if ($clientId === null) {
            return $this->revokeWhere('user_id = ? AND revoked = ?', [(string) $user->authIdentifier(), false], [ParameterType::STRING, ParameterType::BOOLEAN]);
        }

        return $this->revokeWhere(
            'user_id = ? AND client_id = ? AND revoked = ?',
            [(string) $user->authIdentifier(), $clientId, false],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN],
        );
    }

    /** Revoke every token of a client, and return how many access tokens there were. */
    public function revokeForClient(string $clientId): int
    {
        return $this->revokeWhere('client_id = ? AND revoked = ?', [$clientId, false], [ParameterType::STRING, ParameterType::BOOLEAN]);
    }

    /** Remove expired authorization codes, and expired or revoked tokens; returns how many rows went. */
    public function purge(): int
    {
        $now = $this->now();
        $types = [ParameterType::INTEGER, ParameterType::BOOLEAN];
        $removed = (int) $this->connection->executeStatement("DELETE FROM {$this->tables['auth_codes']} WHERE expires_at <= ? OR revoked = ?", [$now, true], $types);
        $removed += (int) $this->connection->executeStatement("DELETE FROM {$this->tables['refresh_tokens']} WHERE expires_at <= ? OR revoked = ?", [$now, true], $types);
        // An access token row stays while a refresh token still refers to it.
        $removed += (int) $this->connection->executeStatement(
            "DELETE FROM {$this->tables['access_tokens']} WHERE (expires_at <= ? OR revoked = ?) AND id NOT IN (SELECT access_token_id FROM {$this->tables['refresh_tokens']})",
            [$now, true],
            $types,
        );

        return $removed;
    }

    /**
     * @internal
     *
     * @param ScopeEntityInterface[] $scopes
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, mixed $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessToken();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        $userId = self::userId($userIdentifier);
        // A client credentials token has no user; version 9 of the library takes no null here.
        if ($userId !== null) {
            $token->setUserIdentifier($userId);
        }

        return $token;
    }

    /** @internal */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->insert($this->tables['access_tokens'], [
            'id' => (string) $accessTokenEntity->getIdentifier(),
            'client_id' => (string) $accessTokenEntity->getClient()->getIdentifier(),
            'user_id' => self::userId($accessTokenEntity->getUserIdentifier()),
            'scopes' => self::scopes($accessTokenEntity->getScopes()),
            'revoked' => false,
            'created_at' => $this->now(),
            'expires_at' => $accessTokenEntity->getExpiryDateTime()->getTimestamp(),
        ]);
    }

    /** @internal */
    public function revokeAccessToken(mixed $tokenId): void
    {
        $this->revoke((string) $tokenId);
    }

    /** @internal Whether an access token was revoked; one that expired, or that is unknown, counts the same. */
    public function isAccessTokenRevoked(mixed $tokenId): bool
    {
        return $this->revokedIn($this->tables['access_tokens'], $tokenId, expiry: true);
    }

    /** @internal */
    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshToken();
    }

    /** @internal */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->insert($this->tables['refresh_tokens'], [
            'id' => (string) $refreshTokenEntity->getIdentifier(),
            'access_token_id' => (string) $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime()->getTimestamp(),
        ]);
    }

    /** @internal */
    public function revokeRefreshToken(mixed $tokenId): void
    {
        $this->revokeIn($this->tables['refresh_tokens'], $tokenId);
    }

    /** @internal */
    public function isRefreshTokenRevoked(mixed $tokenId): bool
    {
        return $this->revokedIn($this->tables['refresh_tokens'], $tokenId);
    }

    /** @internal */
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }

    /** @internal */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->insert($this->tables['auth_codes'], [
            'id' => (string) $authCodeEntity->getIdentifier(),
            'client_id' => (string) $authCodeEntity->getClient()->getIdentifier(),
            'user_id' => self::userId($authCodeEntity->getUserIdentifier()),
            'scopes' => self::scopes($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime()->getTimestamp(),
        ]);
    }

    /** @internal */
    public function revokeAuthCode(mixed $codeId): void
    {
        $this->revokeIn($this->tables['auth_codes'], $codeId);
    }

    /** @internal */
    public function isAuthCodeRevoked(mixed $codeId): bool
    {
        return $this->revokedIn($this->tables['auth_codes'], $codeId);
    }

    /**
     * @param list<mixed> $params
     * @param list<ParameterType> $types
     */
    private function revokeWhere(string $where, array $params, array $types): int
    {
        $this->connection->executeStatement(
            "UPDATE {$this->tables['refresh_tokens']} SET revoked = ? WHERE access_token_id IN (SELECT id FROM {$this->tables['access_tokens']} WHERE {$where})",
            [true, ...$params],
            [ParameterType::BOOLEAN, ...$types],
        );

        return (int) $this->connection->executeStatement(
            "UPDATE {$this->tables['access_tokens']} SET revoked = ? WHERE {$where}",
            [true, ...$params],
            [ParameterType::BOOLEAN, ...$types],
        );
    }

    private function revokeIn(string $table, mixed $id): void
    {
        if (is_string($id)) {
            $this->connection->executeStatement("UPDATE {$table} SET revoked = ? WHERE id = ?", [true, $id], [ParameterType::BOOLEAN, ParameterType::STRING]);
        }
    }

    private function revokedIn(string $table, mixed $id, bool $expiry = false): bool
    {
        if (!is_string($id) || $id === '' || strlen($id) > 100) {
            return true;
        }
        $row = $this->connection->fetchAssociative("SELECT revoked, expires_at FROM {$table} WHERE id = ?", [$id]);
        if ($row === false) {
            return true;
        }
        $row = array_change_key_case($row, CASE_LOWER);

        return (bool) $row['revoked'] || ($expiry && (int) $row['expires_at'] <= $this->now());
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        try {
            $this->connection->insert($table, $row, ['revoked' => ParameterType::BOOLEAN]);
        } catch (UniqueConstraintViolationException) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    private static function userId(mixed $id): ?string
    {
        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /** @param ScopeEntityInterface[] $scopes */
    private static function scopes(array $scopes): string
    {
        return json_encode(array_values(array_map(static fn(ScopeEntityInterface $scope): string => (string) $scope->getIdentifier(), $scopes)), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row */
    private static function issued(array $row): ?IssuedToken
    {
        $row = array_change_key_case($row, CASE_LOWER);
        try {
            $scopes = json_decode((string) $row['scopes'], true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($scopes)) {
            return null;
        }

        return new IssuedToken(
            (string) $row['id'],
            (string) $row['client_id'],
            (string) $row['client_name'],
            $row['user_id'] === null ? null : (string) $row['user_id'],
            array_values(array_filter($scopes, is_string(...))),
            self::time((int) $row['created_at']),
            self::time((int) $row['expires_at']),
        );
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function time(int $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $timestamp)->setTimezone(new DateTimeZone('UTC'));
    }
}
