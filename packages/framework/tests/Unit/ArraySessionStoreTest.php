<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Session\ArraySessionStore;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use Crystoline\Xpsr\Testing\SessionStoreContractTestCase;
use Symfony\Component\Clock\MockClock;

final class ArraySessionStoreTest extends SessionStoreContractTestCase
{
    protected function createStore(int $lifetime, MockClock $clock): SessionStoreInterface
    {
        return new ArraySessionStore($lifetime, $clock);
    }
}
