<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use Closure;
use Crystoline\Xpsr\Notifications\Channel\Channels;
use Crystoline\Xpsr\Notifications\Event\NotificationFailed;
use Crystoline\Xpsr\Notifications\Event\NotificationSent;
use Crystoline\Xpsr\Queue\QueueInterface;
use InvalidArgumentException;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends notifications on their channels, now or from a queue worker; see NotifierInterface.
 *
 * Each recipient gets one ID for the notification, the same on each channel. A channel with
 * nowhere to deliver to a recipient, such as SMS for a user without a phone number, is skipped and
 * logged (info). Delivered and failed channels are dispatched as NotificationSent and
 * NotificationFailed events.
 */
final class Notifier implements NotifierInterface
{
    /** The built-in channels' interfaces, for errors. */
    private const INTERFACES = [
        'mail' => MailNotificationInterface::class,
        'sms' => SmsNotificationInterface::class,
        'database' => DatabaseNotificationInterface::class,
        'webpush' => WebPushNotificationInterface::class,
    ];

    /**
     * @param (Closure(): QueueInterface)|null $queues null without crystoline/xpsr-queue
     * @param string|null $queue notifications.queue: the transport queue() uses by default
     */
    public function __construct(
        private readonly Channels $channels,
        private readonly ?Closure $queues = null,
        private readonly ?string $queue = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    public function send(NotifiableInterface|iterable $to, NotificationInterface $notification): void
    {
        $failures = [];
        $attempts = 0;
        foreach (self::recipients($to) as $recipient) {
            foreach ($this->prepare($recipient, $notification) as $channel => $delivery) {
                $attempts++;
                try {
                    if ($delivery instanceof Throwable) {
                        throw $delivery;
                    }
                    $this->deliver($delivery);
                } catch (Throwable $error) {
                    $failures[] = ['channel' => $channel, 'to' => $recipient, 'error' => $error];
                }
            }
        }
        if ($failures !== []) {
            throw new NotificationFailedException($notification::class, $failures, $attempts);
        }
    }

    public function queue(NotifiableInterface|iterable $to, NotificationInterface $notification, string|int|null $delay = null, ?string $queue = null): void
    {
        if ($this->queues === null) {
            throw new LogicException('Queued notifications need crystoline/xpsr-queue: run composer require crystoline/xpsr-queue, and add Crystoline\Xpsr\Queue\QueueServiceRegistry to app.registry.');
        }
        $queues = ($this->queues)();
        $failures = [];
        $attempts = 0;
        foreach (self::recipients($to) as $recipient) {
            foreach ($this->prepare($recipient, $notification) as $channel => $delivery) {
                $attempts++;
                try {
                    if ($delivery instanceof Throwable) {
                        throw $delivery;
                    }
                    $queues->dispatch(new SendNotification($delivery), $delay, $queue ?? $this->queue);
                } catch (Throwable $error) {
                    $failures[] = ['channel' => $channel, 'to' => $recipient, 'error' => $error];
                }
            }
        }
        if ($failures !== []) {
            throw new NotificationFailedException($notification::class, $failures, $attempts);
        }
    }

    /**
     * The deliveries of $notification to $to, by channel, with their messages made, or the error
     * that kept a message from being made. Skipped channels are left out, and logged.
     *
     * @internal Used by FakeNotifier.
     *
     * @return array<string, Delivery|Throwable>
     *
     * @throws LogicException when the notification's channels() returns something other than its channels
     */
    public function prepare(NotifiableInterface $to, NotificationInterface $notification): array
    {
        $id = self::uuid();
        $key = $to->notifiableKey();
        $prepared = [];
        foreach ($this->channelsFor($notification, $to) as $name) {
            try {
                $message = $this->channels->get($name)->message($notification, $to, $name);
            } catch (Throwable $error) {
                $prepared[$name] = $error;

                continue;
            }
            if ($message === null) {
                $this->logger->info('Skipped the {channel} channel of {notification} for {recipient}, who has no address on it.', [
                    'channel' => $name,
                    'notification' => $notification::class,
                    'recipient' => $key === null ? 'a recipient without a notifiable key' : (string) $key,
                ]);

                continue;
            }
            $prepared[$name] = new Delivery($id, $name, $notification::class, $message, $to->notificationAddress($name), $key);
        }

        return $prepared;
    }

    /**
     * Delivers on the delivery's channel and dispatches NotificationSent or NotificationFailed.
     *
     * @internal Used by send() and by the SendNotification job.
     */
    public function deliver(Delivery $delivery): void
    {
        try {
            $this->channels->get($delivery->channel)->deliver($delivery);
        } catch (Throwable $error) {
            $this->dispatch(new NotificationFailed($delivery, $error), $delivery);

            throw $error;
        }
        $this->dispatch(new NotificationSent($delivery), $delivery);
    }

    /**
     * Dispatches an event; a listener's exception is logged, so a delivered notification is not
     * reported as failed and sent again, and a failure's own error is the one reported.
     */
    private function dispatch(object $event, Delivery $delivery): void
    {
        if ($this->events === null) {
            return;
        }
        try {
            $this->events->dispatch($event);
        } catch (Throwable $error) {
            $this->logger->error('A {event} listener failed for {notification} on {channel}: {error}', [
                'event' => substr((string) strrchr($event::class, '\\'), 1),
                'notification' => $delivery->notification,
                'channel' => $delivery->channel,
                'error' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }

    /** @return list<string> */
    private function channelsFor(NotificationInterface $notification, NotifiableInterface $to): array
    {
        if (!method_exists($notification, 'channels')) {
            return $this->channels->supporting($notification);
        }
        $names = $notification->channels($to);
        if (!is_array($names) || !array_is_list($names)) {
            throw new LogicException(sprintf('%s::channels() must return a list of channel names, such as [\'mail\', \'sms\'].', $notification::class));
        }
        foreach ($names as $name) {
            if (!is_string($name) || !$this->channels->has($name)) {
                throw new LogicException(sprintf(
                    '%s::channels() returned %s, which is not a channel. Channels: %s.',
                    $notification::class,
                    is_string($name) ? $name : get_debug_type($name),
                    implode(', ', $this->channels->names()),
                ));
            }
            if (!$this->channels->get($name)->supports($notification)) {
                throw new LogicException(sprintf(
                    '%s::channels() returned %s, but the notification does not go there%s.',
                    $notification::class,
                    $name,
                    isset(self::INTERFACES[$name]) ? ': implement ' . self::INTERFACES[$name] : '',
                ));
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param NotifiableInterface|iterable<mixed> $to
     * @return iterable<NotifiableInterface>
     */
    private static function recipients(NotifiableInterface|iterable $to): iterable
    {
        if ($to instanceof NotifiableInterface) {
            return [$to];
        }

        return (static function () use ($to): \Generator {
            foreach ($to as $recipient) {
                if (!$recipient instanceof NotifiableInterface) {
                    throw new InvalidArgumentException('Notifications go to NotifiableInterface recipients, or lists of them; got ' . get_debug_type($recipient) . '.');
                }
                yield $recipient;
            }
        })();
    }

    /** A version 7 UUID: time-ordered, so new rows are added at the end of the table's index. */
    private static function uuid(): string
    {
        $hex = str_pad(dechex((int) floor(microtime(true) * 1000)), 12, '0', STR_PAD_LEFT) . bin2hex(random_bytes(10));
        $hex[12] = '7';
        $hex[16] = dechex(8 | ((int) hexdec($hex[16]) & 3));

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }
}
