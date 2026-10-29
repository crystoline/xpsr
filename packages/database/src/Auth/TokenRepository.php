<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\UserInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use JsonException;
use Psr\Clock\ClockInterface;

/**
 * API tokens in a table (auth.guards driver "token"), created by the migration that
 * php xpsr make:token-table writes. Tokens are random ("xpsr_" and 64 hexadecimal characters)
 * and stored only as SHA-256 hashes, so a copy of the table cannot be used to sign in.
 *
 *     $new = $tokens->create($user, 'deploy script', ['tasks:read'], expiresAt: new DateTimeImmutable('+90 days'));
 *     $new->plainText;                    // show once
 *     $tokens->revoke($new->token->id);
 */
final class TokenRepository
{
    public const PREFIX = 'xpsr_';
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';
    private const SCOPE = '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/';

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly string $table = 'api_tokens',
    ) {
        if (!preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('auth.tokens.table must be a plain table name: letters, digits, and underscores.');
        }
    }

    /**
     * @param list<string> $scopes what the token allows; ["*"] for everything
     */
    public function create(UserInterface $user, string $name, array $scopes = ['*'], ?DateTimeImmutable $expiresAt = null): NewAccessToken
    {
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A token name must be 1 to 255 characters, such as "deploy script".');
        }
        foreach ($scopes as $scope) {
            if (!preg_match(self::SCOPE, $scope)) {
                throw new InvalidArgumentException('Scopes must be printable text without spaces, quotes, or backslashes, such as tasks:read.');
            }
        }
        $plainText = self::PREFIX . bin2hex(random_bytes(32));
        $now = $this->now();
        $this->connection->insert($this->table, [
            'token_hash' => hash('sha256', $plainText),
            'user_id' => (string) $user->authIdentifier(),
            'name' => $name,
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'last_used_at' => null,
            'expires_at' => $expiresAt?->getTimestamp(),
        ]);
        $id = (int) $this->connection->lastInsertId();

        return new NewAccessToken($plainText, new AccessToken($id, (string) $user->authIdentifier(), $name, $scopes, self::time($now), null, $expiresAt));
    }

    /** The token with this text, unless it is unknown or expired; records when it was last used. */
    public function find(#[\SensitiveParameter] string $plainText): ?AccessToken
    {
        if (!str_starts_with($plainText, self::PREFIX) || strlen($plainText) > 200) {
            return null;
        }
        $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE token_hash = ?", [hash('sha256', $plainText)]);
        $token = $row === false ? null : self::token($row);
        if ($token === null || ($token->expiresAt !== null && $token->expiresAt->getTimestamp() <= $this->now())) {
            return null;
        }
        // At most one write a minute per token.
        if ($token->lastUsedAt === null || $token->lastUsedAt->getTimestamp() < $this->now() - 60) {
            $this->connection->update($this->table, ['last_used_at' => $this->now()], ['id' => $token->id]);
        }

        return $token;
    }

    /** @return list<AccessToken> the user's tokens, newest first, including expired ones */
    public function forUser(UserInterface $user): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM {$this->table} WHERE user_id = ? ORDER BY id DESC", [(string) $user->authIdentifier()]);

        return array_values(array_filter(array_map(self::token(...), $rows)));
    }

    /** Delete a token; false when there was none with this ID. */
    public function revoke(int $id): bool
    {
        return $this->connection->delete($this->table, ['id' => $id]) > 0;
    }

    /** Delete every token of a user, and return how many there were. */
    public function revokeAll(UserInterface $user): int
    {
        return (int) $this->connection->delete($this->table, ['user_id' => (string) $user->authIdentifier()]);
    }

    /** @param array<string, mixed> $row */
    private static function token(array $row): ?AccessToken
    {
        $row = array_change_key_case($row, CASE_LOWER);
        try {
            $scopes = json_decode((string) $row['scopes'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($scopes) || !array_is_list($scopes)) {
            return null;
        }

        return new AccessToken(
            (int) $row['id'],
            (string) $row['user_id'],
            (string) $row['name'],
            array_values(array_filter($scopes, is_string(...))),
            self::time((int) $row['created_at']),
            $row['last_used_at'] === null ? null : self::time((int) $row['last_used_at']),
            $row['expires_at'] === null ? null : self::time((int) $row['expires_at']),
        );
    }

    private static function time(int $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $timestamp)->setTimezone(new DateTimeZone('UTC'));
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
