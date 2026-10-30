<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Notifications\ChannelInterface;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use RuntimeException;

/** A channel from notifications.channels; the container gives it the Recorder. */
final class SlackChannel implements ChannelInterface
{
    public function __construct(private readonly Recorder $recorder) {}

    public function supports(NotificationInterface $notification): bool
    {
        return $notification instanceof SlackNotificationInterface;
    }

    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): mixed
    {
        if (!$notification instanceof SlackNotificationInterface) {
            throw new RuntimeException('Not a Slack notification.');
        }
        $webhook = $to->notificationAddress($channel);

        return $webhook === null ? null : ['webhook' => $webhook, 'text' => $notification->toSlack($to)];
    }

    public function deliver(Delivery $delivery): void
    {
        $message = $delivery->message;
        if (!is_array($message) || !is_string($message['text'] ?? null)) {
            throw new RuntimeException('Not a Slack message.');
        }
        if (str_contains($message['text'], 'offline')) {
            throw new RuntimeException('Slack is offline.');
        }
        $this->recorder->deliveries[] = $delivery;
    }
}
