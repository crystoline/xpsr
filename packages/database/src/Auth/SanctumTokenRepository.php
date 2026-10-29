<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Auth;

use Crystoline\Xpsr\Auth\UserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use JsonException;
use Psr\Clock\ClockInterface;

/**
 * API tokens in Laravel Sanctum's personal_access_tokens table (auth.guards driver "sanctum"),
 * read and written as Sanctum 4 does. An application that moves from Laravel keeps its clients'
 * tokens, and while both run on the same table, each accepts the other's tokens.
 *
 *     $new = $tokens->create($user, 'mobile app', ['payments:create']);
 *     $new->plainText;   // "42|…", shown once
 *
 * A token is "<id>|<secret>"; the table keeps only the SHA-256 of the secret. Its abilities are
 * its scopes. Each guard accepts only rows whose tokenable_type is the guard's, so an admin's
 * token never counts on the customers' API. Times are stored as Laravel stores them: as
 * "Y-m-d H:i:s" in the application's time zone.
 */
final class SanctumTokenRepository implements TokenStoreInterface
{
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';
    /** As TokenRepository: printable, without spaces, quotes, or backslashes. */
    private const SCOPE = '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/';
    /** The characters of Laravel's Str::random(). */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @param array<string, string> $types guard => the tokenable_type its tokens have, the default guard first
     * @param int|null $expiration seconds a token lasts after it was created, as sanctum.expiration; null for no limit
     * @param string $prefix put before new secrets, as sanctum.token_prefix
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly array $types = [],
        private readonly string $table = 'personal_access_tokens',
        private readonly ?int $expiration = null,
        private readonly string $prefix = '',
    ) {
        if (!preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('auth.sanctum.table must be a plain table name: letters, digits, and underscores.');
        }
    }

    public function create(UserInterface $user, string $name, array $scopes = ['*'], ?DateTimeImmutable $expiresAt = null, ?string $guard = null): NewAccessToken
    {
        $guard = $this->guard($guard);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A token name must be 1 to 255 characters, such as "mobile app".');
        }
        foreach ($scopes as $scope) {
            if (!preg_match(self::SCOPE, $scope)) {
                throw new InvalidArgumentException('Scopes must be printable text without spaces, quotes, or backslashes, such as payments:create.');
            }
        }
        // As Sanctum's generateTokenString(): the prefix, 40 random characters, and their CRC32.
        $entropy = '';
        for ($i = 0; $i < 40; $i++) {
            $entropy .= self::ALPHABET[random_int(0, 61)];
        }
        $secret = $this->prefix . $entropy . hash('crc32b', $entropy);
        $now = $this->clock->now();
        $this->connection->insert($this->table, [
            'tokenable_type' => $this->types[$guard],
            'tokenable_id' => $user->authIdentifier(),
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'last_used_at' => null,
            'expires_at' => $expiresAt === null ? null : $this->format($expiresAt),
            'created_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);
        $id = (int) $this->connection->lastInsertId();
        $token = new AccessToken($id, (string) $user->authIdentifier(), $name, $scopes, $this->parse($this->format($now)), null, $expiresAt, $guard);

        return new NewAccessToken("{$id}|{$secret}", $token);
    }

    /**
     * The token with this text on a guard, unless it is unknown, another guard's, or expired;
     * records when it was last used, at most once a minute.
     */
    public function find(#[\SensitiveParameter] string $plainText, string $guard): ?AccessToken
    {
        $type = $this->types[$guard] ?? null;
        if ($type === null || $plainText === '' || strlen($plainText) > 255) {
            return null;
        }
        // As Sanctum's findToken(): "<id>|<secret>" by ID, or a bare secret by its hash.
        if (str_contains($plainText, '|')) {
            [$id, $secret] = explode('|', $plainText, 2);
            if ($secret === '' || !ctype_digit($id) || strlen($id) > 18) {
                return null;
            }
            $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE id = ?", [(int) $id]);
            if ($row === false || !hash_equals((string) (array_change_key_case($row, CASE_LOWER)['token'] ?? ''), hash('sha256', $secret))) {
                return null;
            }
        } else {
            $row = $this->connection->fetchAssociative("SELECT * FROM {$this->table} WHERE token = ?", [hash('sha256', $plainText)]);
            if ($row === false) {
                return null;
            }
        }
        $row = array_change_key_case($row, CASE_LOWER);
        if ((string) $row['tokenable_type'] !== $type) {
            return null;
        }
        $token = $this->token($row, $guard);
        $now = $this->clock->now();
        if (
            $token === null
            || ($token->expiresAt !== null && $token->expiresAt <= $now)
            || ($this->expiration !== null && $token->createdAt->getTimestamp() + $this->expiration <= $now->getTimestamp())
        ) {
            return null;
        }
        if ($token->lastUsedAt === null || $token->lastUsedAt->getTimestamp() < $now->getTimestamp() - 60) {
            $this->connection->update($this->table, ['last_used_at' => $this->format($now), 'updated_at' => $this->format($now)], ['id' => $token->id]);
        }

        return $token;
    }

    public function forUser(UserInterface $user, ?string $guard = null): array
    {
        $guard = $this->guard($guard);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE tokenable_type = ? AND tokenable_id = ? ORDER BY id DESC",
            [$this->types[$guard], $user->authIdentifier()],
        );

        return array_values(array_filter(array_map(fn(array $row): ?AccessToken => $this->token(array_change_key_case($row, CASE_LOWER), $guard), $rows)));
    }

    public function revoke(int $id): bool
    {
        return $this->connection->delete($this->table, ['id' => $id]) > 0;
    }

    public function revokeAll(UserInterface $user, ?string $guard = null): int
    {
        $guard = $this->guard($guard);

        return (int) $this->connection->delete($this->table, ['tokenable_type' => $this->types[$guard], 'tokenable_id' => $user->authIdentifier()]);
    }

    private function guard(?string $guard): string
    {
        $guard ??= array_key_first($this->types) ?? throw new InvalidArgumentException('No guard in auth.guards uses the sanctum driver.');
        if (!isset($this->types[$guard])) {
            throw new InvalidArgumentException("The guard \"{$guard}\" does not use the sanctum driver. Sanctum guards: " . implode(', ', array_keys($this->types)) . '.');
        }

        return $guard;
    }

    /** @param array<string, mixed> $row with lowercase keys */
    private function token(array $row, string $guard): ?AccessToken
    {
        try {
            $abilities = $row['abilities'] === null ? [] : json_decode((string) $row['abilities'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($abilities)) {
            return null;
        }
        try {
            return new AccessToken(
                (int) $row['id'],
                (string) $row['tokenable_id'],
                (string) $row['name'],
                array_values(array_filter($abilities, is_string(...))),
                // A row without created_at counts as created long ago, so a lifetime ends it.
                $row['created_at'] === null ? new DateTimeImmutable('@0') : $this->parse((string) $row['created_at']),
                $row['last_used_at'] === null ? null : $this->parse((string) $row['last_used_at']),
                $row['expires_at'] === null ? null : $this->parse((string) $row['expires_at']),
                $guard,
            );
        } catch (\Exception) {
            return null;
        }
    }

    private function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone($this->timezone())->format('Y-m-d H:i:s');
    }

    private function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, $this->timezone());
    }

    /** The application's time zone, in which Laravel stores Sanctum's times. */
    private function timezone(): DateTimeZone
    {
        return $this->clock->now()->getTimezone();
    }
}
