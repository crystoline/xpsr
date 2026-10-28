<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Session;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The "session" configuration (config/session.php), validated:
 *
 *   store         file (default), cache, database, array (in memory, for tests), or the ID of a
 *                 service implementing SessionStoreInterface
 *   lifetime      how long a session lasts without requests, such as "2 hours" (default)
 *   prune_every   about one request in this many removes expired sessions (default 100; 0 never)
 *   cookie        name (default xpsr_session), path (/), domain (none), secure (null: on HTTPS
 *                 requests), same_site (Lax, Strict, or None)
 *   file          path: the directory for the file store, relative to the project root (var/sessions)
 *   database      table (sessions) and connection (the default) for the database store
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SessionOptions
{
    private const NAME = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    private function __construct(
        public readonly string $store,
        public readonly int $lifetime,
        public readonly int $pruneEvery,
        public readonly string $cookieName,
        public readonly string $cookiePath,
        public readonly ?string $cookieDomain,
        public readonly ?bool $secure,
        public readonly string $sameSite,
        public readonly string $directory,
        public readonly string $table,
        public readonly ?string $connection,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $root): self
    {
        self::known($config, 'session', ['store', 'lifetime', 'prune_every', 'cookie', 'file', 'database']);
        $store = $config['store'] ?? 'file';
        if (!is_string($store) || $store === '') {
            throw new InvalidArgumentException('session.store must be file, cache, database, array, or a service implementing ' . SessionStoreInterface::class . '.');
        }
        $lifetime = $config['lifetime'] ?? '2 hours';
        $seconds = is_string($lifetime) ? self::seconds($lifetime) : 0;
        if ($seconds < 1) {
            throw new InvalidArgumentException('session.lifetime must be a period such as "2 hours" or "30 minutes".');
        }
        $pruneEvery = $config['prune_every'] ?? 100;
        if (!is_int($pruneEvery) || $pruneEvery < 0) {
            throw new InvalidArgumentException('session.prune_every must be a whole number: about one request in that many removes expired sessions, or 0 for never.');
        }

        $cookie = self::section($config, 'cookie', ['name', 'path', 'domain', 'secure', 'same_site']);
        $name = $cookie['name'] ?? 'xpsr_session';
        if (!is_string($name) || !preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException('session.cookie.name must be a cookie name of letters, digits, underscores, or hyphens.');
        }
        $path = $cookie['path'] ?? '/';
        $domain = $cookie['domain'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || ($domain !== null && (!is_string($domain) || $domain === ''))) {
            throw new InvalidArgumentException('session.cookie.path must start with "/", and session.cookie.domain must be a domain or null.');
        }
        $secure = $cookie['secure'] ?? null;
        if ($secure !== null && !is_bool($secure)) {
            throw new InvalidArgumentException('session.cookie.secure must be true, false, or null (Secure on HTTPS requests).');
        }
        $sameSite = $cookie['same_site'] ?? 'Lax';
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('session.cookie.same_site must be Lax, Strict, or None.');
        }
        if ($sameSite === 'None' && $secure !== true) {
            throw new InvalidArgumentException('session.cookie.same_site None needs session.cookie.secure true: browsers reject SameSite=None cookies that are not Secure.');
        }

        $file = self::section($config, 'file', ['path']);
        $directory = $file['path'] ?? 'var/sessions';
        if (!is_string($directory) || $directory === '') {
            throw new InvalidArgumentException('session.file.path must be a directory, relative to the project root or absolute.');
        }
        $database = self::section($config, 'database', ['table', 'connection']);
        $table = $database['table'] ?? 'sessions';
        if (!is_string($table) || !preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('session.database.table must be a plain table name: letters, digits, and underscores.');
        }
        $connection = $database['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('session.database.connection must name a connection in config/database.php, or be null for the default.');
        }

        return new self(
            $store,
            $seconds,
            $pruneEvery,
            $name,
            $path,
            $domain,
            $secure,
            $sameSite,
            str_starts_with($directory, '/') ? $directory : rtrim($root, '/') . '/' . $directory,
            $table,
            $connection,
        );
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $known
     */
    private static function known(array $config, string $prefix, array $known): void
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option %s.%s. Known options: %s.', $prefix, $key, implode(', ', $known)));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $known
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $key, array $known): array
    {
        $section = $config[$key] ?? [];
        if (!is_array($section)) {
            throw new InvalidArgumentException("session.{$key} must be an array of options: " . implode(', ', $known) . '.');
        }
        self::known($section, "session.{$key}", $known);

        return $section;
    }

    /** The length of a period such as "2 hours" in seconds; 0 when it is not a period. */
    private static function seconds(string $period): int
    {
        try {
            return (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($period))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }
}
