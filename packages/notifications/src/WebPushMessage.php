<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A browser push notification:
 *
 *     return new WebPushMessage(
 *         title: 'Payment received',
 *         body: 'NGN 5,000 from Ada',
 *         icon: '/icons/payment.png',
 *         data: ['url' => '/payments/' . $this->payment->reference],
 *         ttl: '1 day',
 *     );
 *
 * The browser gets JSON: the title and the options of the Notification API (showNotification()),
 * without the empty ones, as Laravel's web push channel sends them. A service worker shows it:
 *
 *     self.addEventListener('push', (event) => {
 *         const message = event.data.json();
 *         event.waitUntil(self.registration.showNotification(message.title, message));
 *     });
 *
 * ttl, urgency, and topic are for the push service, not the browser: how long it keeps the message
 * for a browser that is offline (default four weeks), how soon to deliver it (very-low, low,
 * normal, or high), and a name that replaces an undelivered message with the same topic.
 */
final class WebPushMessage
{
    private const DIRECTIONS = ['auto', 'ltr', 'rtl'];
    private const URGENCIES = ['very-low', 'low', 'normal', 'high'];

    /** Seconds, or null for the push service's default. */
    public readonly ?int $ttl;

    /**
     * @param array<array-key, mixed> $actions buttons, as ['action' => 'view', 'title' => 'View', 'icon' => ...];
     *                                         the service worker's notificationclick event gets the action
     * @param array<array-key, mixed> $vibrate a vibration pattern in milliseconds, such as [200, 100, 200]
     * @param int|string|null $ttl seconds, or a period such as "1 day"
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $icon = null,
        public readonly ?string $image = null,
        public readonly ?string $badge = null,
        public readonly ?string $tag = null,
        public readonly mixed $data = null,
        public readonly array $actions = [],
        public readonly ?bool $requireInteraction = null,
        public readonly ?bool $renotify = null,
        public readonly ?string $lang = null,
        public readonly ?string $dir = null,
        public readonly array $vibrate = [],
        int|string|null $ttl = null,
        public readonly ?string $urgency = null,
        public readonly ?string $topic = null,
    ) {
        if ($title === '') {
            throw new InvalidArgumentException('A web push message needs a title.');
        }
        foreach ($actions as $action) {
            if (!is_array($action) || !is_string($action['action'] ?? null) || !is_string($action['title'] ?? null) || !is_string($action['icon'] ?? '')) {
                throw new InvalidArgumentException('Web push actions must be arrays with an "action" and a "title", and optionally an "icon", such as [\'action\' => \'view\', \'title\' => \'View\'].');
            }
        }
        if ($dir !== null && !in_array($dir, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException('A web push message\'s dir must be auto, ltr, or rtl.');
        }
        foreach ($vibrate as $milliseconds) {
            if (!is_int($milliseconds) || $milliseconds < 0) {
                throw new InvalidArgumentException('A web push message\'s vibrate must be a list of milliseconds, such as [200, 100, 200].');
            }
        }
        if ($urgency !== null && !in_array($urgency, self::URGENCIES, true)) {
            throw new InvalidArgumentException('A web push message\'s urgency must be very-low, low, normal, or high.');
        }
        if ($topic !== null && !preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $topic)) {
            throw new InvalidArgumentException('A web push message\'s topic must be 1 to 32 letters, digits, underscores, or hyphens.');
        }
        $this->ttl = $ttl === null ? null : self::seconds($ttl);
    }

    /**
     * What the browser gets, as Laravel's WebPushMessage::toArray(): the title and the options,
     * in its order, without empty ones.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter([
            'title' => $this->title,
            'actions' => $this->actions,
            'badge' => $this->badge,
            'body' => $this->body,
            'dir' => $this->dir,
            'icon' => $this->icon,
            'image' => $this->image,
            'lang' => $this->lang,
            'renotify' => $this->renotify,
            'requireInteraction' => $this->requireInteraction,
            'tag' => $this->tag,
            'vibrate' => $this->vibrate,
            'data' => $this->data,
        ]);
    }

    /** The payload as JSON. Throws JsonException for data JSON cannot encode. */
    public function json(): string
    {
        return json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The push service's options, as minishlink/web-push takes them.
     *
     * @return array{TTL?: int, urgency?: string, topic?: string}
     */
    public function options(): array
    {
        return array_filter(['TTL' => $this->ttl, 'urgency' => $this->urgency, 'topic' => $this->topic], static fn(mixed $value): bool => $value !== null);
    }

    private static function seconds(int|string $period): int
    {
        if (is_int($period) && $period >= 0) {
            return $period;
        }
        if (is_string($period)) {
            try {
                $seconds = (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($period))->getTimestamp();
            } catch (\Exception) {
                $seconds = -1;
            }
            if ($seconds >= 0) {
                return $seconds;
            }
        }
        throw new InvalidArgumentException('A web push message\'s ttl must be a number of seconds or a period such as "1 day".');
    }
}
