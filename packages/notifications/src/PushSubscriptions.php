<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * The browsers that subscribed to a recipient's web push notifications. The browser subscribes
 * with the VAPID public key, and the application keeps what it gets:
 *
 *     // GET /push/key, for pushManager.subscribe({userVisibleOnly: true, applicationServerKey: key})
 *     return ['key' => $subscriptions->publicKey()];
 *
 *     // POST /push/subscriptions, with the browser's subscription.toJSON()
 *     $subscriptions->subscribe($user, $request->getParsedBody());
 *
 *     // DELETE /push/subscriptions, when the browser unsubscribes
 *     $subscriptions->unsubscribe($user, $endpoint);
 *
 * subscribe() checks what the browser sent, and refuses endpoints outside
 * notifications.webpush.hosts, because the server sends requests to them.
 *
 * The table is the one Laravel's web push channel (laravel-notification-channels/webpush) uses
 * (php xpsr make:push-subscriptions-table for a new one), so an application that moves from
 * Laravel keeps its subscribed browsers.
 */
final class PushSubscriptions
{
    /** The push services of Chrome and other Chromium browsers, Firefox, Edge, and Safari. */
    public const DEFAULT_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', '*.notify.windows.com', '*.push.apple.com'];
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';
    private const ENCODINGS = ['aes128gcm', 'aesgcm'];

    /**
     * @param list<string> $hosts the hosts endpoints may have: names, "*.example.com" for its
     *                            subdomains, or "*" for any
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly string $table = 'push_subscriptions',
        private readonly array $hosts = self::DEFAULT_HOSTS,
        private readonly ?string $publicKey = null,
    ) {
        if (!preg_match(self::TABLE, $table)) {
            throw new InvalidArgumentException('notifications.webpush.table must be a plain table name: letters, digits, and underscores.');
        }
    }

    /** The VAPID public key, which the browser subscribes with (applicationServerKey). */
    public function publicKey(): string
    {
        if ($this->publicKey === null) {
            throw new LogicException('Set notifications.webpush.public_key (VAPID_PUBLIC_KEY): php xpsr webpush:keys --write makes a pair.');
        }

        return $this->publicKey;
    }

    /**
     * Keeps a browser's subscription for $owner: the browser's subscription.toJSON(),
     * {"endpoint": ..., "keys": {"p256dh": ..., "auth": ...}}. A browser subscribed for someone
     * else, such as after another user signed in on it, moves to $owner.
     *
     * @param array<array-key, mixed> $subscription
     *
     * @throws InvalidArgumentException for a subscription that is not one, or an endpoint outside notifications.webpush.hosts
     */
    public function subscribe(NotifiableInterface|NotifiableKey $owner, array $subscription, string $contentEncoding = 'aes128gcm'): PushSubscription
    {
        $key = self::key($owner);
        $endpoint = $subscription['endpoint'] ?? null;
        $keys = $subscription['keys'] ?? null;
        if (!is_string($endpoint) || !is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
            throw new InvalidArgumentException('A push subscription has an endpoint and keys: {"endpoint": "https://…", "keys": {"p256dh": "…", "auth": "…"}}.');
        }
        if (strlen($endpoint) > 1024 || !preg_match('/\A[\x21-\x7E]+\z/', $endpoint) || !$this->allows($endpoint)) {
            throw new InvalidArgumentException('A push subscription\'s endpoint must be an https:// URL of a push service in notifications.webpush.hosts.');
        }
        if (!self::keys($keys['p256dh'], null)) {
            throw new InvalidArgumentException('A push subscription\'s p256dh key must be a P-256 public key, in URL-safe base64.');
        }
        if (!self::keys(null, $keys['auth'])) {
            throw new InvalidArgumentException('A push subscription\'s auth key must be 16 bytes, in URL-safe base64.');
        }
        if (!in_array($contentEncoding, self::ENCODINGS, true)) {
            throw new InvalidArgumentException('A push subscription\'s content encoding must be aes128gcm or aesgcm.');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $row = [
            'subscribable_type' => $key->type,
            'subscribable_id' => $key->id,
            'public_key' => $keys['p256dh'],
            'auth_token' => $keys['auth'],
            'content_encoding' => $contentEncoding,
            'updated_at' => $now,
        ];
        // Look first: an insert that fails on the unique endpoint would end a PostgreSQL transaction.
        $known = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table)
            ->where('endpoint = :endpoint')
            ->setParameter('endpoint', $endpoint)
            ->executeQuery()
            ->fetchOne();
        if ((int) $known > 0) {
            $this->connection->update($this->table, $row, ['endpoint' => $endpoint]);
        } else {
            try {
                $this->connection->insert($this->table, ['endpoint' => $endpoint, ...$row, 'created_at' => $now]);
            } catch (UniqueConstraintViolationException) {
                // Another request kept the same browser first.
                $this->connection->update($this->table, $row, ['endpoint' => $endpoint]);
            }
        }

        return new PushSubscription($endpoint, $keys['p256dh'], $keys['auth'], $contentEncoding);
    }

    /** Forgets $owner's subscription with this endpoint; false when $owner has none. */
    public function unsubscribe(NotifiableInterface|NotifiableKey $owner, string $endpoint): bool
    {
        $key = self::key($owner);

        return $this->connection->delete($this->table, ['endpoint' => $endpoint, 'subscribable_type' => $key->type, 'subscribable_id' => (string) $key->id]) > 0;
    }

    /**
     * $owner's subscriptions. Rows without valid keys are left out: those without keys can only
     * take pushes without a message, and a key that is not one would fail the push to the
     * owner's other browsers too.
     *
     * @return list<PushSubscription>
     */
    public function of(NotifiableInterface|NotifiableKey $owner): array
    {
        $key = self::key($owner);
        $rows = $this->connection->createQueryBuilder()
            ->select('endpoint', 'public_key', 'auth_token', 'content_encoding')
            ->from($this->table)
            ->where('subscribable_type = :type')
            ->andWhere('subscribable_id = :id')
            ->orderBy('id')
            ->setParameter('type', $key->type)
            ->setParameter('id', (string) $key->id)
            ->executeQuery()
            ->fetchAllAssociative();
        $subscriptions = [];
        foreach ($rows as $row) {
            if (!is_string($row['endpoint']) || !is_string($row['public_key']) || !is_string($row['auth_token']) || !self::keys($row['public_key'], $row['auth_token'])) {
                continue;
            }
            $encoding = $row['content_encoding'];
            $subscriptions[] = new PushSubscription(
                $row['endpoint'],
                $row['public_key'],
                $row['auth_token'],
                is_string($encoding) && in_array($encoding, self::ENCODINGS, true) ? $encoding : 'aes128gcm',
            );
        }

        return $subscriptions;
    }

    /** Whether the server may push to this endpoint: https, on a host in notifications.webpush.hosts. */
    public function allows(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ($this->hosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === '*' || $allowed === $host || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forgets a subscription the push service says has expired.
     *
     * @internal Used by the webpush channel.
     */
    public function forget(string $endpoint): void
    {
        $this->connection->delete($this->table, ['endpoint' => $endpoint]);
    }

    private static function key(NotifiableInterface|NotifiableKey $owner): NotifiableKey
    {
        $key = $owner instanceof NotifiableKey ? $owner : $owner->notifiableKey();
        if ($key === null) {
            throw new InvalidArgumentException('Push subscriptions belong to a recipient with a notifiable key.');
        }

        return $key;
    }

    /** Whether these are a browser's keys: a P-256 public key (p256dh) and a 16-byte secret (auth); null skips one. */
    private static function keys(?string $public, ?string $secret): bool
    {
        if ($public !== null) {
            $point = self::decode($public);
            if ($point === null || strlen($point) !== 65 || $point[0] !== "\x04") {
                return false;
            }
        }

        return $secret === null || strlen((string) self::decode($secret)) === 16;
    }

    /** URL-safe base64, with or without padding, as browsers give keys; null for anything else. */
    private static function decode(string $value): ?string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
