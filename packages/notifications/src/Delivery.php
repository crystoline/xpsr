<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * One notification to one recipient on one channel, with its finished message. A queued
 * notification carries one to the worker for each channel, and the NotificationSent and
 * NotificationFailed events describe one.
 *
 * Not internal: queued messages keep this class's name.
 */
final class Delivery
{
    /**
     * @param string $id the notification's ID for this recipient, the same on each channel: a
     *                   UUID, which the database channel uses as its row's ID
     * @param class-string<NotificationInterface> $notification the notification's class
     * @param mixed $message what the channel delivers: a Symfony Email for mail, an SmsMessage for
     *                       sms, ['type' => ..., 'data' => [...]] for database, a WebPushMessage for
     *                       webpush, or what your own channel's message() made
     * @param string|null $address the recipient's address on this channel, if it has one
     * @param NotifiableKey|null $key the recipient's notifiable key, if it has one
     */
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        public readonly string $notification,
        public readonly mixed $message,
        public readonly ?string $address = null,
        public readonly ?NotifiableKey $key = null,
    ) {}

    /** The recipient as logs and errors name it: its notifiable key, never its address. */
    public function recipient(): string
    {
        return $this->key === null ? 'a recipient without a notifiable key' : (string) $this->key;
    }
}
