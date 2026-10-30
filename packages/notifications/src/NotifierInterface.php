<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * Sends notifications, now or from a queue worker:
 *
 *     $notifier->send($user, new PaymentReceived($payment));
 *     $notifier->queue($user, new PaymentReceived($payment));
 *     $notifier->send($admins, new LargeTransfer($transfer));               // a list of recipients
 *     $notifier->send(Recipient::phone('+2348012345678'), new SignUpCode($code));
 */
interface NotifierInterface
{
    /**
     * Sends $notification to each recipient on each of its channels, now. Every channel is tried;
     * then, if any failed, NotificationFailedException lists the failures.
     *
     * @param NotifiableInterface|iterable<NotifiableInterface> $to
     *
     * @throws NotificationFailedException
     */
    public function send(NotifiableInterface|iterable $to, NotificationInterface $notification): void;

    /**
     * Makes the messages now and queues one job for each recipient and channel, so a channel that
     * fails is retried alone. Needs crystoline/xpsr-queue.
     *
     * @param NotifiableInterface|iterable<NotifiableInterface> $to
     * @param string|int|null $delay seconds, or a period such as "10 minutes", before it is sent
     * @param string|null $queue the transport from queue.transports; notifications.queue without one
     *
     * @throws NotificationFailedException when a message could not be made or queued; the others are queued
     */
    public function queue(NotifiableInterface|iterable $to, NotificationInterface $notification, string|int|null $delay = null, ?string $queue = null): void;
}
