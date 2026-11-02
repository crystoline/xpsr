<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine\Tests\Fixtures;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;

/** Counts flushes: an orm.listeners entry. */
final class CountingSubscriber implements EventSubscriber
{
    public int $flushes = 0;

    public function getSubscribedEvents(): array
    {
        return [Events::postFlush];
    }

    public function postFlush(): void
    {
        $this->flushes++;
    }
}
