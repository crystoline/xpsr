<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * A way to deliver notifications, named in notifications.channels:
 *
 *     'channels' => ['slack' => App\Notification\SlackChannel::class],
 *
 *     interface SlackNotificationInterface extends NotificationInterface
 *     {
 *         public function toSlack(NotifiableInterface $to): string;
 *     }
 *
 *     final class SlackChannel implements ChannelInterface
 *     {
 *         public function __construct(private readonly ClientInterface $http) {}
 *
 *         public function supports(NotificationInterface $notification): bool
 *         {
 *             return $notification instanceof SlackNotificationInterface;
 *         }
 *
 *         public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): mixed
 *         {
 *             $webhook = $to->notificationAddress($channel);
 *
 *             return $webhook === null ? null : ['webhook' => $webhook, 'text' => $notification->toSlack($to)];
 *         }
 *
 *         public function deliver(Delivery $delivery): void
 *         {
 *             // POST $delivery->message['text'] to $delivery->message['webhook']; throw when it fails
 *         }
 *     }
 *
 * The channel comes from the container, so its constructor can take services. A channel with the
 * name of a built-in one (mail, sms, database, webpush) replaces it.
 */
interface ChannelInterface
{
    /**
     * Whether $notification goes to this channel when its class does not choose channels: usually
     * whether it implements the channel's interface.
     */
    public function supports(NotificationInterface $notification): bool;

    /**
     * The finished message for $to, made when the notification is sent or queued, or null when $to
     * cannot receive it here, such as without an address. A queued message is serialized, so keep
     * it to plain values and serializable objects.
     *
     * @param string $channel the channel's name in notifications.channels, for notificationAddress()
     */
    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): mixed;

    /**
     * Sends a message message() made: at once for send(), in a queue worker for queue(). Throw when
     * it fails, so send() reports it and a queued notification is retried.
     */
    public function deliver(Delivery $delivery): void;
}
