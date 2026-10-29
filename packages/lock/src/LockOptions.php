<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The "lock" configuration (config/lock.php, optional), validated:
 *
 *   store     flock (the default: files in var/locks, for one server), database (the
 *             application's database, through crystoline/xpsr-database), redlock (the servers in
 *             redlock), or a Symfony Lock DSN such as redis://localhost:6379
 *   redlock   for the redlock store: three or more independent Redis servers, as DSNs; a lock
 *             needs more than half of them
 *   database  for the database store: connection (default: the default connection) and table
 *             (default: lock_keys)
 *   prefix    put in front of every key, so applications can share a store (default: app)
 *   ttl       how long a lock lasts unless refreshed, in seconds or a period such as
 *             "30 seconds" (default: 5 minutes); flock and other stores without expiry keep a
 *             lock until it is released or its process ends
 */
final class LockOptions
{
    private const KEYS = ['store', 'redlock', 'database', 'prefix', 'ttl'];

    /** @param list<string> $redlock */
    public function __construct(
        public readonly string $store = 'flock',
        public readonly array $redlock = [],
        public readonly ?string $connection = null,
        public readonly string $table = 'lock_keys',
        public readonly string $prefix = 'app',
        public readonly float $ttl = 300.0,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Unknown option lock.{$key}. Known options: " . implode(', ', self::KEYS) . '.');
            }
        }
        $store = $config['store'] ?? 'flock';
        if (!is_string($store) || $store === '') {
            throw new InvalidArgumentException('lock.store must be flock, database, redlock, or a Symfony Lock DSN such as redis://localhost:6379.');
        }
        $redlock = $config['redlock'] ?? [];
        if (!is_array($redlock) || !array_is_list($redlock)) {
            throw new InvalidArgumentException('lock.redlock must be a list of Redis DSNs, such as [\'redis://redis-1:6379\', \'redis://redis-2:6379\', \'redis://redis-3:6379\'].');
        }
        foreach ($redlock as $server) {
            if (!is_string($server) || !preg_match('#\Arediss?://#', $server)) {
                throw new InvalidArgumentException('lock.redlock must list Redis DSNs that start with redis:// or rediss://.');
            }
        }
        if ($store === 'redlock' && count($redlock) < 3) {
            throw new InvalidArgumentException('The redlock store needs three or more independent Redis servers in lock.redlock; a lock needs more than half of them. For one server, set lock.store to its DSN, such as redis://localhost:6379.');
        }
        $database = $config['database'] ?? [];
        if (!is_array($database)) {
            throw new InvalidArgumentException('lock.database must be an array with connection and table.');
        }
        $connection = $database['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('lock.database.connection must name a database connection, or be null for the default one.');
        }
        $table = $database['table'] ?? 'lock_keys';
        if (!is_string($table) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $table)) {
            throw new InvalidArgumentException('lock.database.table must be a plain table name: letters, digits, and underscores.');
        }
        $prefix = $config['prefix'] ?? 'app';
        if (!is_string($prefix) || !preg_match('/\A[\w.-]+\z/', $prefix)) {
            throw new InvalidArgumentException('lock.prefix may contain only letters, digits, "_", ".", and "-".');
        }

        return new self($store, $redlock, $connection, $table, $prefix, self::seconds($config['ttl'] ?? 300, 'lock.ttl'));
    }

    /**
     * Seconds from a number or a period such as "30 seconds" or "5 minutes".
     *
     * @internal
     */
    public static function seconds(mixed $period, string $what, bool $zero = false): float
    {
        $seconds = match (true) {
            is_int($period), is_float($period) => (float) $period,
            is_string($period) => self::parse($period),
            default => null,
        };
        if ($seconds === null || $seconds < 0 || (!$zero && $seconds === 0.0)) {
            throw new InvalidArgumentException("{$what} must be " . ($zero ? 'zero or more' : 'more than zero') . ' seconds, or a period such as "30 seconds".');
        }

        return $seconds;
    }

    private static function parse(string $period): ?float
    {
        if (is_numeric($period)) {
            return (float) $period;
        }
        try {
            $interval = DateInterval::createFromDateString($period);
        } catch (\Exception) {
            return null;
        }
        $seconds = (new DateTimeImmutable('@0'))->add($interval)->getTimestamp();

        // A string that is not a period, such as "soon", reads as no time at all.
        return $seconds > 0 || preg_match('/\A\s*0+\s*[a-z]+\s*\z/i', $period) ? (float) $seconds : null;
    }
}
