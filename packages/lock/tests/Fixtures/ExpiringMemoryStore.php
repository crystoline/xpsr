<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Lock\Tests\Fixtures;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/** An in-memory store whose locks expire, as Redis and database locks do. */
final class ExpiringMemoryStore implements PersistingStoreInterface
{
    private readonly InMemoryStore $store;

    public function __construct()
    {
        $this->store = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $key->reduceLifetime($ttl);
        $this->store->putOffExpiration($key, $ttl);
    }
}
