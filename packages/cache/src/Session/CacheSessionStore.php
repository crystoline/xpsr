<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Session;

use Crystoline\Xpsr\Session\SessionStoreInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Keeps sessions in the application cache (session.store "cache"). Each entry records when it was
 * written, and expires by the application clock like the other stores; the session lifetime is
 * also its time to live, so the cache removes old entries itself.
 *
 * The cache must outlive requests: a filesystem or server cache, not the array or null driver.
 * Clearing the cache (cache:clear) signs everybody out.
 */
final class CacheSessionStore implements SessionStoreInterface
{
    private const PREFIX = 'xpsr_session.';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $lifetime,
        private readonly ClockInterface $clock,
    ) {
        if ($lifetime < 1) {
            throw new InvalidArgumentException('The session lifetime must be at least 1 second.');
        }
    }

    public function read(string $id): ?string
    {
        $entry = $this->cache->get(self::key($id));
        if (!is_string($entry) || !preg_match('/\A(\d+):/', $entry, $match)) {
            return null;
        }

        return (int) $match[1] + $this->lifetime <= $this->now() ? null : substr($entry, strlen($match[0]));
    }

    public function write(string $id, string $payload): void
    {
        $this->cache->set(self::key($id), $this->now() . ':' . $payload, $this->lifetime);
    }

    public function destroy(string $id): void
    {
        $this->cache->delete(self::key($id));
    }

    public function prune(): int
    {
        // Entries expire by their time to live.
        return 0;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function key(string $id): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)) {
            throw new InvalidArgumentException('A session ID must be 1 to 128 letters, digits, underscores, or hyphens.');
        }

        return self::PREFIX . $id;
    }
}
