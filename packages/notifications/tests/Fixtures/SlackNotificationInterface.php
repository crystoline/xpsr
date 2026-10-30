<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;

interface SlackNotificationInterface extends NotificationInterface
{
    public function toSlack(NotifiableInterface $to): string;
}
