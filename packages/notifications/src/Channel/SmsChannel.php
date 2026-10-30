<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Channel;

use Closure;
use Crystoline\Xpsr\Notifications\ChannelInterface;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use Crystoline\Xpsr\Notifications\SmsNotificationInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * The "sms" channel: SmsNotificationInterface's text, sent through the provider
 * notifications.sms.dsn names.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class SmsChannel implements ChannelInterface
{
    /** @param Closure(): TexterInterface $texter */
    public function __construct(private readonly Closure $texter) {}

    public function supports(NotificationInterface $notification): bool
    {
        return $notification instanceof SmsNotificationInterface;
    }

    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): ?SmsMessage
    {
        if (!$notification instanceof SmsNotificationInterface) {
            throw new LogicException($notification::class . ' does not implement SmsNotificationInterface.');
        }
        $phone = $to->notificationAddress($channel);
        if ($phone === null) {
            return null;
        }
        $sms = $notification->toSms($to);
        if ($sms instanceof SmsMessage) {
            return $sms;
        }
        if (trim($sms) === '') {
            throw new InvalidArgumentException($notification::class . '::toSms() returned no text.');
        }

        return new SmsMessage($phone, $sms);
    }

    public function deliver(Delivery $delivery): void
    {
        if (!$delivery->message instanceof SmsMessage) {
            throw new LogicException('The sms channel delivers Symfony SmsMessage messages, not ' . get_debug_type($delivery->message) . '.');
        }
        ($this->texter)()->send($delivery->message);
    }
}
