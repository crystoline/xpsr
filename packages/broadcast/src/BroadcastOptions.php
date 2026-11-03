<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Broadcast;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The "broadcast" configuration (config/broadcast.php), with BROADCAST_* environment variables:
 *
 *   hub                the hub's URL the application publishes to (BROADCAST_HUB_URL), such as
 *                      http://localhost:3000/.well-known/mercure
 *   public_hub         the URL browsers subscribe at (BROADCAST_PUBLIC_HUB_URL; default: hub)
 *   secret             the key the hub verifies tokens with (BROADCAST_SECRET): its
 *                      MERCURE_PUBLISHER_JWT_KEY and MERCURE_SUBSCRIBER_JWT_KEY. At least 32 bytes
 *                      (48 for HS384, 64 for HS512); "base64:..." is decoded
 *   publisher_secret   when the hub has two keys, the publisher's (default: secret)
 *   subscriber_secret  and the subscriber's (default: secret)
 *   algorithm          HS256 (default), HS384, or HS512, as the hub is configured
 *   driver             mercure (default), or null to drop updates (BROADCAST_DRIVER)
 *   queue              true (default): a queue job publishes each update once the open transaction
 *                      commits, when crystoline/xpsr-queue is registered; a transport name sends
 *                      the jobs there; false publishes in the request
 *   client             the http_client.clients entry to publish with (default: the default client)
 *   subscriptions      the topic selectors a signed-in user may receive private updates on: a
 *                      list, or a closure taking the UserInterface and returning one
 *   ttl                how long a subscriber's authorization lasts, such as "1 hour" (default);
 *                      null for as long as the browser session
 *   cookie             the authorization cookie's name (default mercureAuthorization, as the hub expects)
 *   cookie_domain      its Domain, when the hub is on another subdomain (default: none)
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class BroadcastOptions
{
    /** The algorithms and the bytes their keys need. */
    public const ALGORITHMS = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64];
    public const DRIVERS = ['mercure', 'null'];
    private const COOKIE_NAME = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

    /** @param list<string>|Closure $subscriptions */
    private function __construct(
        public readonly ?string $hub,
        public readonly ?string $publicHub,
        private readonly ?string $publisherSecret,
        private readonly ?string $subscriberSecret,
        public readonly string $algorithm,
        public readonly string $driver,
        public readonly string|bool $queue,
        public readonly ?string $client,
        public readonly array|Closure $subscriptions,
        public readonly ?int $ttl,
        public readonly string $cookie,
        public readonly ?string $cookieDomain,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, mixed> $environment the BROADCAST_* variables
     */
    public static function from(array $config, array $environment = []): self
    {
        $known = ['hub', 'public_hub', 'secret', 'publisher_secret', 'subscriber_secret', 'algorithm', 'driver', 'queue', 'client', 'subscriptions', 'ttl', 'cookie', 'cookie_domain'];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown option broadcast.%s. Known options: %s.', $key, implode(', ', $known)));
            }
        }
        $algorithm = $config['algorithm'] ?? 'HS256';
        if (!is_string($algorithm) || !isset(self::ALGORITHMS[strtoupper($algorithm)])) {
            throw new InvalidArgumentException('broadcast.algorithm must be HS256, HS384, or HS512.');
        }
        $algorithm = strtoupper($algorithm);
        $hub = self::url($config['hub'] ?? $environment['BROADCAST_HUB_URL'] ?? null, 'broadcast.hub (or BROADCAST_HUB_URL)');
        $publicHub = self::url($config['public_hub'] ?? $environment['BROADCAST_PUBLIC_HUB_URL'] ?? null, 'broadcast.public_hub (or BROADCAST_PUBLIC_HUB_URL)') ?? $hub;
        $secret = self::secret($config['secret'] ?? $environment['BROADCAST_SECRET'] ?? null, 'broadcast.secret (or BROADCAST_SECRET)', $algorithm);
        $publisherSecret = self::secret($config['publisher_secret'] ?? null, 'broadcast.publisher_secret', $algorithm) ?? $secret;
        $subscriberSecret = self::secret($config['subscriber_secret'] ?? null, 'broadcast.subscriber_secret', $algorithm) ?? $secret;
        $driver = $config['driver'] ?? $environment['BROADCAST_DRIVER'] ?? 'mercure';
        if (!is_string($driver) || !in_array(strtolower($driver), self::DRIVERS, true)) {
            throw new InvalidArgumentException('broadcast.driver (or BROADCAST_DRIVER) must be mercure or null.');
        }
        $queue = $config['queue'] ?? true;
        if (!is_bool($queue) && (!is_string($queue) || $queue === '')) {
            throw new InvalidArgumentException('broadcast.queue must be true, false, or the name of a queue transport.');
        }
        $client = $config['client'] ?? null;
        if ($client !== null && (!is_string($client) || $client === '')) {
            throw new InvalidArgumentException('broadcast.client must be the name of an http_client.clients entry.');
        }
        $subscriptions = $config['subscriptions'] ?? [];
        if (!$subscriptions instanceof Closure) {
            if (!is_array($subscriptions) || !array_is_list($subscriptions) || $subscriptions !== array_filter($subscriptions, static fn(mixed $selector): bool => is_string($selector) && $selector !== '')) {
                throw new InvalidArgumentException('broadcast.subscriptions must be a list of topic selectors, or a closure taking the user and returning one.');
            }
        }
        $ttl = array_key_exists('ttl', $config) ? $config['ttl'] : '1 hour';
        $ttl = $ttl === null ? null : self::seconds($ttl, 'broadcast.ttl');
        $cookie = $config['cookie'] ?? 'mercureAuthorization';
        if (!is_string($cookie) || preg_match(self::COOKIE_NAME, $cookie) !== 1) {
            throw new InvalidArgumentException('broadcast.cookie must be a cookie name, such as mercureAuthorization.');
        }
        $cookieDomain = $config['cookie_domain'] ?? null;
        if ($cookieDomain !== null && (!is_string($cookieDomain) || $cookieDomain === '')) {
            throw new InvalidArgumentException('broadcast.cookie_domain must be a domain, such as example.com, or null.');
        }

        return new self($hub, $publicHub, $publisherSecret, $subscriberSecret, $algorithm, strtolower($driver), $queue, $client, $subscriptions, $ttl, $cookie, $cookieDomain);
    }

    /** The hub's URL the application publishes to. */
    public function hubUrl(): string
    {
        return $this->hub ?? throw new InvalidArgumentException('broadcast.hub is not set (BROADCAST_HUB_URL): the Mercure hub\'s URL, such as http://localhost:3000/.well-known/mercure.');
    }

    /** The URL browsers subscribe at. */
    public function publicHubUrl(): string
    {
        return $this->publicHub ?? throw new InvalidArgumentException('broadcast.hub is not set (BROADCAST_HUB_URL): the Mercure hub\'s URL, such as http://localhost:3000/.well-known/mercure.');
    }

    public function publisherSecret(): string
    {
        return $this->publisherSecret ?? throw new InvalidArgumentException('broadcast.secret is not set (BROADCAST_SECRET): the key the Mercure hub verifies tokens with, its MERCURE_PUBLISHER_JWT_KEY.');
    }

    public function subscriberSecret(): string
    {
        return $this->subscriberSecret ?? throw new InvalidArgumentException('broadcast.secret is not set (BROADCAST_SECRET): the key the Mercure hub verifies tokens with, its MERCURE_SUBSCRIBER_JWT_KEY.');
    }

    /** Seconds from a count or a period such as "1 hour"; the setting's name is in the error. */
    public static function seconds(mixed $value, string $setting): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A\d+\s*(second|minute|hour|day)s?\z/i', trim($value)) === 1) {
            $seconds = (new DateTimeImmutable('@0'))->modify('+' . trim($value))->getTimestamp();
            if ($seconds > 0) {
                return $seconds;
            }
        }

        throw new InvalidArgumentException("{$setting} must be a number of seconds above zero, or a period such as \"1 hour\".");
    }

    private static function url(mixed $value, string $setting): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException("{$setting} must be an http or https URL, such as http://localhost:3000/.well-known/mercure.");
        }

        return $value;
    }

    private static function secret(mixed $value, string $setting, string $algorithm): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$setting} must be a string: the key the Mercure hub verifies tokens with.");
        }
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new InvalidArgumentException("{$setting} starts with \"base64:\" but the rest is not base64.");
            }
            $value = $decoded;
        }
        $bytes = self::ALGORITHMS[$algorithm];
        if (strlen($value) < $bytes) {
            throw new InvalidArgumentException("{$setting} must be at least {$bytes} bytes for {$algorithm}; give the hub the same key.");
        }

        return $value;
    }
}
