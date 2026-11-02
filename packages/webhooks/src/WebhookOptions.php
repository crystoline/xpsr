<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

use Crystoline\Xpsr\Webhooks\Inbound\InboundVerifier;
use InvalidArgumentException;

/**
 * The "webhooks" configuration (config/webhooks.php, optional), validated:
 *
 *   client              a named HTTP client (config/http_client.php) to send with; null for the
 *                       default client
 *   header_prefix       the start of the headers a delivery carries: <prefix>-Id, -Event,
 *                       -Timestamp, and -Signature (default: X-Webhook)
 *   user_agent          the User-Agent of deliveries (default: XPSR-Webhooks)
 *   tolerance           how old a signed timestamp may be when a webhook is verified, in seconds
 *                       or a period (default: 5 minutes)
 *   queue               the queue deliveries are dispatched on (default: queue.default)
 *   allow_private_urls  whether endpoints may point at loopback, private, and link-local
 *                       addresses; for development only (default: false)
 *   connection          the database connection of the tables (default: the default connection)
 *   endpoints_table     default webhook_endpoints
 *   deliveries_table    default webhook_deliveries
 *   inbound             name => how that provider signs its webhooks (see InboundVerifier), for
 *                       VerifyWebhook::named($name)
 */
final class WebhookOptions
{
    private const KEYS = ['client', 'header_prefix', 'user_agent', 'tolerance', 'queue', 'allow_private_urls', 'connection', 'endpoints_table', 'deliveries_table', 'inbound'];
    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /** @param array<string, InboundVerifier> $inbound */
    public function __construct(
        public readonly ?string $client = null,
        public readonly string $headerPrefix = 'X-Webhook',
        public readonly string $userAgent = 'XPSR-Webhooks',
        public readonly int $tolerance = 300,
        public readonly ?string $queue = null,
        public readonly bool $allowPrivateUrls = false,
        public readonly ?string $connection = null,
        public readonly string $endpointsTable = 'webhook_endpoints',
        public readonly string $deliveriesTable = 'webhook_deliveries',
        public readonly array $inbound = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Unknown option webhooks.{$key}. Known options: " . implode(', ', self::KEYS) . '.');
            }
        }
        $client = $config['client'] ?? null;
        if ($client !== null && (!is_string($client) || $client === '')) {
            throw new InvalidArgumentException('webhooks.client must name a client of config/http_client.php, or be null for the default client.');
        }
        $prefix = $config['header_prefix'] ?? 'X-Webhook';
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z][A-Za-z0-9-]*[A-Za-z0-9]\z/', $prefix)) {
            throw new InvalidArgumentException('webhooks.header_prefix must be a header name start such as X-Webhook: letters, digits, and "-".');
        }
        $userAgent = $config['user_agent'] ?? 'XPSR-Webhooks';
        if (!is_string($userAgent) || trim($userAgent) === '' || preg_match('/[\r\n]/', $userAgent)) {
            throw new InvalidArgumentException('webhooks.user_agent must be one line of text.');
        }
        $tolerance = Period::seconds($config['tolerance'] ?? 300, 'webhooks.tolerance');
        $queue = $config['queue'] ?? null;
        if ($queue !== null && (!is_string($queue) || $queue === '')) {
            throw new InvalidArgumentException('webhooks.queue must name a queue, or be null for the default one.');
        }
        $allowPrivate = $config['allow_private_urls'] ?? false;
        if (!is_bool($allowPrivate)) {
            throw new InvalidArgumentException('webhooks.allow_private_urls must be true or false.');
        }
        $connection = $config['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('webhooks.connection must name a database connection, or be null for the default one.');
        }
        $tables = [];
        foreach (['endpoints_table' => 'webhook_endpoints', 'deliveries_table' => 'webhook_deliveries'] as $key => $default) {
            $table = $config[$key] ?? $default;
            if (!is_string($table) || !preg_match(self::TABLE, $table)) {
                throw new InvalidArgumentException("webhooks.{$key} must be a plain table name: letters, digits, and underscores.");
            }
            $tables[$key] = $table;
        }
        if ($tables['endpoints_table'] === $tables['deliveries_table']) {
            throw new InvalidArgumentException('webhooks.endpoints_table and webhooks.deliveries_table must differ.');
        }
        $inbound = $config['inbound'] ?? [];
        if (!is_array($inbound)) {
            throw new InvalidArgumentException('webhooks.inbound must be an array of verifiers by name.');
        }
        $verifiers = [];
        foreach ($inbound as $name => $verifier) {
            if (!is_string($name) || !preg_match('/\A[a-z0-9_-]+\z/', $name)) {
                throw new InvalidArgumentException('webhooks.inbound names use lowercase letters, digits, "_", and "-".');
            }
            if (!is_array($verifier)) {
                throw new InvalidArgumentException("webhooks.inbound.{$name} must be an array with scheme and secret.");
            }
            $verifiers[$name] = InboundVerifier::fromArray($name, $verifier, $prefix, $tolerance);
        }

        return new self($client, $prefix, $userAgent, $tolerance, $queue, $allowPrivate, $connection, $tables['endpoints_table'], $tables['deliveries_table'], $verifiers);
    }

    public function verifier(string $name): InboundVerifier
    {
        return $this->inbound[$name] ?? throw new InvalidArgumentException(sprintf(
            'No webhook verifier "%s" in webhooks.inbound%s.',
            $name,
            $this->inbound === [] ? '' : '; known: ' . implode(', ', array_keys($this->inbound)),
        ));
    }
}
