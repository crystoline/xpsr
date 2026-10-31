<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Testing;

use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use Crystoline\Xpsr\Notifications\Notifier;
use Crystoline\Xpsr\Notifications\NotifierInterface;
use Crystoline\Xpsr\Notifications\Recipient;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A notifier for tests: it makes each channel's message, as the real notifier does, and keeps it
 * instead of sending it.
 *
 *     $this->swap(NotifierInterface::class, $notifier = $this->application()->get(FakeNotifier::class));
 *     $this->postJson('/payments', [...]);
 *
 *     $sent = $notifier->sentTo($user, PaymentReceived::class);
 *     self::assertCount(1, $sent);
 *     self::assertSame(['mail', 'sms'], $sent[0]->channels());
 *     self::assertStringContainsString('NGN 5,000', $sent[0]->message('sms')->getSubject());
 *
 * A message that cannot be made, such as from a template that does not exist, fails the call, as
 * it would in the application.
 */
final class FakeNotifier implements NotifierInterface
{
    /** @var list<SentNotification> */
    private array $sent = [];

    public function __construct(private readonly Notifier $notifier) {}

    public function send(NotifiableInterface|iterable $to, NotificationInterface $notification): void
    {
        $this->keep($to, $notification, false, null, null);
    }

    public function queue(NotifiableInterface|iterable $to, NotificationInterface $notification, string|int|null $delay = null, ?string $queue = null): void
    {
        $this->keep($to, $notification, true, $delay, $queue);
    }

    /**
     * The notifications kept, one for each recipient, all or of one class, in order.
     *
     * @param class-string<NotificationInterface>|null $class
     * @return list<SentNotification>
     */
    public function sent(?string $class = null): array
    {
        return array_values(array_filter($this->sent, static fn(SentNotification $sent): bool => $class === null || $sent->notification instanceof $class));
    }

    /**
     * The notifications kept for one recipient, all or of one class. A recipient with a notifiable
     * key matches by key, so another instance of the same user matches.
     *
     * @param class-string<NotificationInterface>|null $class
     * @return list<SentNotification>
     */
    public function sentTo(NotifiableInterface $to, ?string $class = null): array
    {
        return array_values(array_filter($this->sent($class), static fn(SentNotification $sent): bool => self::same($sent->to, $to)));
    }

    /**
     * A notification of this class was kept: at least one, or exactly $times; for one recipient
     * when $to is given.
     *
     * @param class-string<NotificationInterface> $class
     */
    public function assertSent(string $class, ?NotifiableInterface $to = null, ?int $times = null): void
    {
        $sent = $to === null ? $this->sent($class) : $this->sentTo($to, $class);
        $toText = $to === null ? '' : ' to the recipient';
        if ($times === null) {
            Assert::assertNotEmpty($sent, "No {$class} notification{$toText} was sent." . $this->sentSummary());
        } else {
            Assert::assertCount($times, $sent, sprintf('Expected %d %s notification(s)%s, not %d.%s', $times, $class, $toText, count($sent), $this->sentSummary()));
        }
    }

    /** @param class-string<NotificationInterface> $class */
    public function assertNotSent(string $class, ?NotifiableInterface $to = null): void
    {
        $sent = $to === null ? $this->sent($class) : $this->sentTo($to, $class);
        Assert::assertCount(0, $sent, sprintf('%d %s notification(s)%s were sent.', count($sent), $class, $to === null ? '' : ' to the recipient'));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Notifications were sent.' . $this->sentSummary());
    }

    public function clear(): void
    {
        $this->sent = [];
    }

    private function sentSummary(): string
    {
        $classes = array_map(static fn(SentNotification $sent): string => $sent->notification::class, $this->sent);

        return $classes === [] ? ' Nothing was sent.' : ' Sent: ' . implode(', ', $classes) . '.';
    }

    /** @param NotifiableInterface|iterable<mixed> $to */
    private function keep(NotifiableInterface|iterable $to, NotificationInterface $notification, bool $queued, string|int|null $delay, ?string $queue): void
    {
        foreach ($to instanceof NotifiableInterface ? [$to] : $to as $recipient) {
            if (!$recipient instanceof NotifiableInterface) {
                throw new InvalidArgumentException('Notifications go to NotifiableInterface recipients, or lists of them; got ' . get_debug_type($recipient) . '.');
            }
            $deliveries = [];
            foreach ($this->notifier->prepare($recipient, $notification) as $channel => $delivery) {
                if ($delivery instanceof Throwable) {
                    throw $delivery;
                }
                $deliveries[$channel] = $delivery;
            }
            $this->sent[] = new SentNotification($notification, $recipient, $deliveries, $queued, $delay, $queue);
        }
    }

    private static function same(NotifiableInterface $a, NotifiableInterface $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $first = $a->notifiableKey();
        $second = $b->notifiableKey();
        if ($first !== null && $second !== null) {
            return $first->equals($second);
        }

        return $a instanceof Recipient && $b instanceof Recipient && $a == $b;
    }
}
