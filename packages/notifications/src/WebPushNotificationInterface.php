<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * A notification pushed to the recipient's browsers, on the "webpush" channel: to each browser
 * PushSubscriptions has for their notifiable key. Needs minishlink/web-push and
 * crystoline/xpsr-database.
 */
interface WebPushNotificationInterface extends NotificationInterface
{
    public function toWebPush(NotifiableInterface $to): WebPushMessage;
}
