<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Notifications\Delivery;

/** What the test channel delivered and which events were dispatched. */
final class Recorder
{
    /** @var list<Delivery> */
    public array $deliveries = [];

    /** @var list<object> */
    public array $events = [];
}
