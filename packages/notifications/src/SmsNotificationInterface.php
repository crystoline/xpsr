<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use Symfony\Component\Notifier\Message\SmsMessage;

/**
 * A notification sent by SMS, on the "sms" channel, to the recipient's "sms" address, through the
 * provider notifications.sms.dsn names.
 */
interface SmsNotificationInterface extends NotificationInterface
{
    /**
     * The text, or a Symfony SmsMessage for a sender ID or a provider's options:
     *
     *     return new SmsMessage((string) $to->notificationAddress('sms'), $text, from: 'MPAY');
     */
    public function toSms(NotifiableInterface $to): string|SmsMessage;
}
