<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Tests\Fixtures;

use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/** An in-memory lock store that records which locks were taken and released, and for how long. */
final class RecordingStore implements PersistingStoreInterface
{
    /** @var list<string> */
    public array $saved = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<float> */
    public array $ttls = [];

    private readonly InMemoryStore $store;

    /** @param bool $unreachable fail every save, as a store whose server is down does */
    public function __construct(private readonly bool $unreachable = false)
    {
        $this->store = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        $this->saved[] = (string) $key;
        if ($this->unreachable) {
            throw new LockStorageException('Connection refused.');
        }
        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->deleted[] = (string) $key;
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->ttls[] = $ttl;
        $this->store->putOffExpiration($key, $ttl);
    }
}
