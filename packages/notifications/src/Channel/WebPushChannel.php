<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Channel;

use Closure;
use Crystoline\Xpsr\Notifications\ChannelInterface;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use Crystoline\Xpsr\Notifications\PushSubscriptions;
use Crystoline\Xpsr\Notifications\WebPushMessage;
use Crystoline\Xpsr\Notifications\WebPushNotificationInterface;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The "webpush" channel: WebPushNotificationInterface's message, pushed to each browser the
 * recipient subscribed, through minishlink/web-push.
 *
 * A subscription the push service says has expired (404 or 410) is deleted. Other failures are
 * logged; the delivery fails, and a queued one is retried, only when no browser got the message,
 * because a retry sends it to every browser again.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class WebPushChannel implements ChannelInterface
{
    /** The largest payload push services take, before encryption (minishlink's Encryption::MAX_PAYLOAD_LENGTH). */
    private const MAX_PAYLOAD = 4078;

    /**
     * @param Closure(): WebPush $webPush
     * @param Closure(): PushSubscriptions $subscriptions
     */
    public function __construct(
        private readonly Closure $webPush,
        private readonly Closure $subscriptions,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(NotificationInterface $notification): bool
    {
        return $notification instanceof WebPushNotificationInterface;
    }

    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): ?WebPushMessage
    {
        if (!$notification instanceof WebPushNotificationInterface) {
            throw new LogicException($notification::class . ' does not implement WebPushNotificationInterface.');
        }
        if ($to->notifiableKey() === null) {
            return null;
        }
        $message = $notification->toWebPush($to);
        try {
            $length = strlen($message->json());
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The web push message of ' . $notification::class . " must have data JSON can encode: {$error->getMessage()}.", previous: $error);
        }
        if ($length > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException(sprintf('The web push message of %s is %d bytes as JSON; push services take at most %d. Send a link in data instead of the content.', $notification::class, $length, self::MAX_PAYLOAD));
        }

        return $message;
    }

    public function deliver(Delivery $delivery): void
    {
        $message = $delivery->message;
        if (!$message instanceof WebPushMessage || $delivery->key === null) {
            throw new LogicException('The webpush channel delivers WebPushMessage messages to a recipient with a notifiable key.');
        }
        $subscriptions = ($this->subscriptions)();
        $browsers = [];
        foreach ($subscriptions->of($delivery->key) as $subscription) {
            if (!$subscriptions->allows($subscription->endpoint)) {
                $this->logger->warning('Skipped a push subscription of {recipient}: its endpoint\'s host is not in notifications.webpush.hosts.', ['recipient' => $delivery->recipient(), 'host' => parse_url($subscription->endpoint, PHP_URL_HOST)]);

                continue;
            }
            $browsers[] = $subscription;
        }
        if ($browsers === []) {
            return;
        }
        $webPush = ($this->webPush)();
        $payload = $message->json();
        foreach ($browsers as $browser) {
            $webPush->queueNotification(new Subscription($browser->endpoint, $browser->publicKey, $browser->authToken, $browser->contentEncoding), $payload, $message->options());
        }
        $delivered = 0;
        /** @var list<MessageSentReport> $failed */
        $failed = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $delivered++;
            } elseif ($report->isSubscriptionExpired()) {
                $subscriptions->forget($report->getEndpoint());
                $this->logger->info('Deleted an expired push subscription of {recipient}.', ['recipient' => $delivery->recipient()]);
            } else {
                $failed[] = $report;
                $this->logger->warning('A browser of {recipient} did not get {notification}: {reason}', [
                    'recipient' => $delivery->recipient(),
                    'notification' => $delivery->notification,
                    'reason' => $report->getReason(),
                    'status' => $report->getResponse()?->getStatusCode(),
                ]);
            }
        }
        if ($delivered === 0 && $failed !== []) {
            throw new RuntimeException(sprintf('No browser of %s got the web push (%d tried): %s', $delivery->recipient(), count($failed), $failed[0]->getReason()));
        }
    }
}
