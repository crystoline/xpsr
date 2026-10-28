<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\CacheServiceRegistry;
use Crystoline\Xpsr\Cache\Session\CacheSessionStore;
use Crystoline\Xpsr\Registry\SessionServiceRegistry;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use Crystoline\Xpsr\Testing\SessionStoreContractTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Clock\MockClock;

/** The cache store passes the session store contract, and is what session.store "cache" selects. */
final class CacheSessionStoreTest extends SessionStoreContractTestCase
{
    protected function createStore(int $lifetime, MockClock $clock): SessionStoreInterface
    {
        return new CacheSessionStore(new Psr16Cache(new ArrayAdapter()), $lifetime, $clock);
    }

    protected function prunes(): bool
    {
        return false;
    }

    public function testSessionStoreCacheSelectsItWithTheSessionLifetime(): void
    {
        $root = sys_get_temp_dir() . '/xpsr cache sessions ' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0777, true);
        try {
            $application = new Application($root);
            $config = $application->getConfig();
            $config->set('cache', ['driver' => 'array']);
            $config->set('session', ['store' => 'cache', 'lifetime' => '1 hour']);
            $config->set('app.registry', [CacheServiceRegistry::class, SessionServiceRegistry::class]);
            $application->boot();

            $store = $application->get(SessionStoreInterface::class);
            self::assertInstanceOf(CacheSessionStore::class, $store);
            $store->write('abc', 'payload');
            self::assertSame('payload', $store->read('abc'));
            $item = $application->get(\Psr\Cache\CacheItemPoolInterface::class)->getItem('xpsr_session.abc');
            self::assertTrue($item->isHit(), 'Sessions are namespaced in the application cache.');
        } finally {
            @rmdir($root . '/config');
            @rmdir($root);
        }
    }
}
