<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Session\FileSessionStore;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use Crystoline\Xpsr\Testing\SessionStoreContractTestCase;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;
use InvalidArgumentException;
use Symfony\Component\Clock\MockClock;

/** The file store passes the session store contract, and keeps its files private. */
final class FileSessionStoreTest extends SessionStoreContractTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = TemporaryDirectory::create('sessions');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    protected function createStore(int $lifetime, MockClock $clock): SessionStoreInterface
    {
        return new FileSessionStore($this->root . '/var/sessions', $lifetime, $clock);
    }

    public function testTheDirectoryAndFilesAreReadableOnlyByTheOwner(): void
    {
        $store = $this->createStore(self::LIFETIME, $this->clock);
        $store->write('abc', 'payload');

        self::assertSame(['var/sessions/sess_abc'], TemporaryDirectory::files($this->root), 'No temporary files are left.');
        self::assertSame(0700, fileperms($this->root . '/var/sessions') & 0777);
        self::assertSame(0600, fileperms($this->root . '/var/sessions/sess_abc') & 0777);
    }

    public function testIdsThatCouldLeaveTheDirectoryAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createStore(self::LIFETIME, $this->clock)->write('../escape', 'payload');
    }
}
