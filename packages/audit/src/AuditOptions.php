<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use InvalidArgumentException;

/**
 * The "audit" configuration (config/audit.php, optional), validated:
 *
 *   table       the log's table (default: audit_log)
 *   connection  the database connection of the table (default: the default connection)
 *   except      fields never recorded as changes, for every model and entity (default:
 *               created_at, updated_at, deleted_at)
 *   redact      fields whose values are recorded as "[redacted]", for every model and entity
 *               (default: password, password_hash, remember_token, secret, token)
 */
final class AuditOptions
{
    public const REDACTED = '[redacted]';
    private const KEYS = ['table', 'connection', 'except', 'redact'];

    /**
     * @param list<string> $except
     * @param list<string> $redact
     */
    public function __construct(
        public readonly string $table = 'audit_log',
        public readonly ?string $connection = null,
        public readonly array $except = ['created_at', 'updated_at', 'deleted_at'],
        public readonly array $redact = ['password', 'password_hash', 'remember_token', 'secret', 'token'],
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Unknown option audit.{$key}. Known options: " . implode(', ', self::KEYS) . '.');
            }
        }
        $table = $config['table'] ?? 'audit_log';
        if (!is_string($table) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $table)) {
            throw new InvalidArgumentException('audit.table must be a plain table name: letters, digits, and underscores.');
        }
        $connection = $config['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('audit.connection must name a database connection, or be null for the default one.');
        }

        return new self(
            $table,
            $connection,
            self::fields($config['except'] ?? ['created_at', 'updated_at', 'deleted_at'], 'audit.except'),
            self::fields($config['redact'] ?? ['password', 'password_hash', 'remember_token', 'secret', 'token'], 'audit.redact'),
        );
    }

    /** @return list<string> */
    private static function fields(mixed $fields, string $what): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new InvalidArgumentException("{$what} must be a list of field names.");
        }
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                throw new InvalidArgumentException("{$what} must be a list of field names.");
            }
        }

        return $fields;
    }
}
