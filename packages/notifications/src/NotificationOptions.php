<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use InvalidArgumentException;

/**
 * The "notifications" configuration (config/notifications.php), validated:
 *
 *   channels  more channels: name => a ChannelInterface class, made by the container; a built-in
 *             name (mail, sms, database, webpush) replaces that channel
 *   queue     the transport Notifier::queue() uses (default: queue.default)
 *   sms       dsn: the SMS provider, such as termii://API_KEY@default?from=MPAY with
 *             symfony/termii-notifier, "<dsn> || <dsn>" to fail over to a second provider,
 *             log://default (the default: written to the application log), array://default (kept
 *             in memory, for tests), or null://null;
 *             factories: more Symfony Notifier transport factories, as classes the container makes
 *   database  table (default notifications), connection (default: the default connection)
 *   webpush   public_key and private_key (VAPID; php xpsr webpush:keys makes them), subject (a
 *             mailto: address or https:// URL push services can reach you at; default app.url),
 *             table (default push_subscriptions), connection, hosts (the push services endpoints
 *             may be on; default PushSubscriptions::DEFAULT_HOSTS, ['*'] for any)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class NotificationOptions
{
    private const KNOWN = ['channels', 'queue', 'sms', 'database', 'webpush'];
    private const NAME = '/\A[A-Za-z0-9_-]+\z/';

    /**
     * @param array<string, class-string> $channels
     * @param list<class-string> $smsFactories
     * @param list<string> $hosts
     */
    private function __construct(
        public readonly array $channels,
        public readonly ?string $queue,
        public readonly string $smsDsn,
        public readonly array $smsFactories,
        public readonly string $table,
        public readonly ?string $connection,
        public readonly ?string $publicKey,
        public readonly ?string $privateKey,
        public readonly ?string $subject,
        public readonly string $pushTable,
        public readonly ?string $pushConnection,
        public readonly array $hosts,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, ?string $url = null): self
    {
        self::known($config, 'notifications', self::KNOWN);
        $channels = $config['channels'] ?? [];
        if (!is_array($channels)) {
            throw new InvalidArgumentException('notifications.channels must map channel names to classes, such as [\'slack\' => App\Notification\SlackChannel::class].');
        }
        foreach ($channels as $name => $class) {
            if (!is_string($name) || !preg_match(self::NAME, $name) || !is_string($class) || !class_exists($class)) {
                throw new InvalidArgumentException('notifications.channels must map channel names (letters, digits, underscores, hyphens) to existing classes, such as [\'slack\' => App\Notification\SlackChannel::class].');
            }
        }
        $queue = $config['queue'] ?? null;
        if ($queue !== null && (!is_string($queue) || $queue === '')) {
            throw new InvalidArgumentException('notifications.queue must name a transport in queue.transports, or be null for queue.default.');
        }

        $sms = self::section($config, 'sms', ['dsn', 'factories']);
        $dsn = $sms['dsn'] ?? 'log://default';
        if (!is_string($dsn) || !str_contains($dsn, '://')) {
            throw new InvalidArgumentException('notifications.sms.dsn must be a DSN such as termii://API_KEY@default?from=MPAY or log://default.');
        }
        $factories = $sms['factories'] ?? [];
        if (!is_array($factories) || !array_is_list($factories)) {
            throw new InvalidArgumentException('notifications.sms.factories must be a list of Symfony Notifier transport factory classes.');
        }
        foreach ($factories as $factory) {
            if (!is_string($factory) || !class_exists($factory)) {
                throw new InvalidArgumentException('notifications.sms.factories must be a list of Symfony Notifier transport factory classes.');
            }
        }

        $database = self::section($config, 'database', ['table', 'connection']);
        [$table, $connection] = self::table($database, 'notifications.database', 'notifications');

        $webpush = self::section($config, 'webpush', ['public_key', 'private_key', 'subject', 'table', 'connection', 'hosts']);
        [$pushTable, $pushConnection] = self::table($webpush, 'notifications.webpush', 'push_subscriptions');
        $public = self::optional($webpush['public_key'] ?? null, 'notifications.webpush.public_key');
        if ($public !== null && strlen((string) self::decode($public)) !== 65) {
            throw new InvalidArgumentException('notifications.webpush.public_key must be a VAPID public key: 65 bytes in URL-safe base64, as php xpsr webpush:keys makes.');
        }
        $private = self::optional($webpush['private_key'] ?? null, 'notifications.webpush.private_key');
        if ($private !== null && strlen((string) self::decode($private)) !== 32) {
            throw new InvalidArgumentException('notifications.webpush.private_key must be a VAPID private key: 32 bytes in URL-safe base64, as php xpsr webpush:keys makes.');
        }
        $subject = self::optional($webpush['subject'] ?? null, 'notifications.webpush.subject') ?? ($url === '' ? null : $url);
        if ($subject !== null && !preg_match('#\A(mailto:\S+@\S+|https?://\S+)\z#', $subject)) {
            throw new InvalidArgumentException('notifications.webpush.subject must be a mailto: address or an https:// URL, where push services can reach you.');
        }
        $hosts = $webpush['hosts'] ?? PushSubscriptions::DEFAULT_HOSTS;
        if (!is_array($hosts) || !array_is_list($hosts) || $hosts === []) {
            throw new InvalidArgumentException('notifications.webpush.hosts must list the hosts of push services, such as fcm.googleapis.com or *.push.apple.com, or be [\'*\'] for any.');
        }
        foreach ($hosts as $host) {
            if (!is_string($host) || !preg_match('/\A(\*|(\*\.)?[A-Za-z0-9.-]+)\z/', $host)) {
                throw new InvalidArgumentException('notifications.webpush.hosts must list the hosts of push services, such as fcm.googleapis.com or *.push.apple.com, or be [\'*\'] for any.');
            }
        }

        /**
         * @var array<string, class-string> $channels
         * @var list<class-string> $factories
         */
        return new self($channels, $queue, $dsn, $factories, $table, $connection, $public, $private, $subject, $pushTable, $pushConnection, $hosts);
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $known
     */
    private static function known(array $config, string $prefix, array $known): void
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option %s.%s. Known options: %s.', $prefix, $key, implode(', ', $known)));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $known
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $name, array $known): array
    {
        $section = $config[$name] ?? [];
        if (!is_array($section)) {
            throw new InvalidArgumentException("notifications.{$name} must be an array: " . implode(', ', $known) . '.');
        }
        self::known($section, "notifications.{$name}", $known);

        return $section;
    }

    /**
     * @param array<array-key, mixed> $section
     * @return array{string, string|null}
     */
    private static function table(array $section, string $prefix, string $default): array
    {
        $table = $section['table'] ?? $default;
        $connection = $section['connection'] ?? null;
        if (!is_string($table) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $table) || ($connection !== null && !is_string($connection))) {
            throw new InvalidArgumentException("{$prefix}.table must be a plain table name, and {$prefix}.connection a connection name or null.");
        }

        return [$table, $connection];
    }

    private static function optional(mixed $value, string $option): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$option} must be a string.");
        }

        return $value;
    }

    private static function decode(string $value): ?string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
