<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Testing;

use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use LogicException;

/** A notification FakeNotifier kept for one recipient, with the message made for each channel. */
final class SentNotification
{
    /** @param array<string, Delivery> $deliveries by channel */
    public function __construct(
        public readonly NotificationInterface $notification,
        public readonly NotifiableInterface $to,
        public readonly array $deliveries,
        public readonly bool $queued,
        public readonly string|int|null $delay = null,
        public readonly ?string $queue = null,
    ) {}

    /** @return list<string> the channels it went to; skipped ones are not among them */
    public function channels(): array
    {
        return array_keys($this->deliveries);
    }

    /**
     * The message made for a channel: a Symfony Email for mail, an SmsMessage for sms,
     * ['type' => ..., 'data' => [...]] for database, a WebPushMessage for webpush.
     */
    public function message(string $channel): mixed
    {
        if (!isset($this->deliveries[$channel])) {
            throw new LogicException(sprintf('%s did not go to %s; its channels were: %s.', $this->notification::class, $channel, implode(', ', $this->channels()) ?: 'none'));
        }

        return $this->deliveries[$channel]->message;
    }
}
