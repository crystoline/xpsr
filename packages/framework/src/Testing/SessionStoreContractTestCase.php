<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Testing;

use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The behavior every session store must provide.
 *
 * A store package extends this class in its own tests and implements createStore(). The store
 * must measure time with the given clock, or pass it to whatever expires its entries, so that
 * the expiry cases can move time forward.
 */
abstract class SessionStoreContractTestCase extends TestCase
{
    protected const LIFETIME = 600;

    protected MockClock $clock;

    /** A store whose sessions last LIFETIME seconds without writes, measured by $clock. */
    abstract protected function createStore(int $lifetime, MockClock $clock): SessionStoreInterface;

    /** Whether prune() reports what it removed; false for stores whose entries expire by themselves. */
    protected function prunes(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-28 12:00:00');
    }

    public function testAnUnknownIdHasNoPayload(): void
    {
        self::assertNull($this->createStore(self::LIFETIME, $this->clock)->read(Session::newId()));
    }

    public function testAPayloadIsReadBackUnchanged(): void
    {
        $store = $this->createStore(self::LIFETIME, $this->clock);
        $id = Session::newId();
        $payload = '{"data":{"name":"Zoë","note":"a\\/b \u{1F600}"},"flash":[],"token":null}' . str_repeat('x', 70_000);
        $store->write($id, $payload);

        self::assertSame($payload, $store->read($id));
        self::assertNull($store->read(Session::newId()), 'Other IDs are unaffected.');
    }

    public function testWritingReplacesThePayload(): void
    {
        $store = $this->createStore(self::LIFETIME, $this->clock);
        $id = Session::newId();
        $store->write($id, 'first');
        $store->write($id, 'second');
        $store->write($id, 'second');

        self::assertSame('second', $store->read($id));
    }

    public function testADestroyedSessionIsGone(): void
    {
        $store = $this->createStore(self::LIFETIME, $this->clock);
        [$kept, $destroyed] = [Session::newId(), Session::newId()];
        $store->write($kept, 'kept');
        $store->write($destroyed, 'destroyed');
        $store->destroy($destroyed);
        $store->destroy(Session::newId());

        self::assertNull($store->read($destroyed));
        self::assertSame('kept', $store->read($kept));
    }

    public function testASessionExpiresOnceItHasNotBeenWrittenForTheLifetime(): void
    {
        $store = $this->createStore(self::LIFETIME, $this->clock);
        $id = Session::newId();
        $store->write($id, 'payload');
        $this->clock->sleep(self::LIFETIME - 1);
        self::assertSame('payload', $store->read($id));

        $store->write($id, 'payload');
        $this->clock->sleep(self::LIFETIME - 1);
        self::assertSame('payload', $store->read($id), 'Writing restarts the lifetime.');

        $this->clock->sleep(1);
        self::assertNull($store->read($id));
    }

    public function testPruningRemovesOnlyExpiredSessions(): void
    {
        $store = $this->createStore(self::LIFETIME, $this->clock);
        [$old, $older, $fresh] = [Session::newId(), Session::newId(), Session::newId()];
        $store->write($older, 'older');
        $this->clock->sleep(10);
        $store->write($old, 'old');
        $this->clock->sleep(self::LIFETIME);
        $store->write($fresh, 'fresh');

        $removed = $store->prune();
        if ($this->prunes()) {
            self::assertSame(2, $removed);
        }
        self::assertSame(0, $this->prunes() ? $store->prune() : 0, 'Nothing is left to prune.');
        self::assertNull($store->read($old));
        self::assertNull($store->read($older));
        self::assertSame('fresh', $store->read($fresh));
    }
}
