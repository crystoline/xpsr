<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Tests;

use Crystoline\Xpsr\Webhooks\WebhookOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookOptionsTest extends TestCase
{
    public function testDefaultsAndValues(): void
    {
        $defaults = WebhookOptions::fromArray([]);
        self::assertNull($defaults->client);
        self::assertSame('X-Webhook', $defaults->headerPrefix);
        self::assertSame(300, $defaults->tolerance);
        self::assertFalse($defaults->allowPrivateUrls);
        self::assertSame('webhook_endpoints', $defaults->endpointsTable);
        self::assertSame('webhook_deliveries', $defaults->deliveriesTable);
        self::assertSame([], $defaults->inbound);

        $options = WebhookOptions::fromArray([
            'client' => 'hooks', 'header_prefix' => 'X-Shop', 'user_agent' => 'Shop/2', 'tolerance' => '10 minutes', 'queue' => 'webhooks',
            'allow_private_urls' => true, 'connection' => 'other', 'endpoints_table' => 'hook_endpoints', 'deliveries_table' => 'hook_deliveries',
            'inbound' => ['stripe' => ['scheme' => 'stripe', 'secret' => 's'], 'partner' => ['scheme' => 'xpsr', 'secret' => 'p']],
        ]);
        self::assertSame('hooks', $options->client);
        self::assertSame(600, $options->tolerance);
        self::assertSame('webhooks', $options->queue);
        self::assertTrue($options->allowPrivateUrls);
        self::assertSame('other', $options->connection);
        self::assertSame('Stripe-Signature', $options->verifier('stripe')->header);
        self::assertSame('X-Shop-Signature', $options->verifier('partner')->header, 'The xpsr scheme follows the header prefix.');
        self::assertSame(600, $options->verifier('partner')->tolerance, 'The default tolerance is the global one.');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['headers' => 'X'], 'Unknown option webhooks.headers. Known options: client, header_prefix, user_agent, tolerance, queue, allow_private_urls, connection, endpoints_table, deliveries_table, inbound.'];
        yield 'prefix' => [['header_prefix' => 'X Webhook'], 'webhooks.header_prefix must be a header name start such as X-Webhook'];
        yield 'tolerance' => [['tolerance' => 'soon'], 'webhooks.tolerance must be more than zero seconds, or a period such as "5 minutes".'];
        yield 'private flag' => [['allow_private_urls' => 'yes'], 'webhooks.allow_private_urls must be true or false.'];
        yield 'table' => [['deliveries_table' => 'webhook-deliveries'], 'webhooks.deliveries_table must be a plain table name'];
        yield 'same tables' => [['deliveries_table' => 'webhook_endpoints'], 'webhooks.endpoints_table and webhooks.deliveries_table must differ.'];
        yield 'inbound name' => [['inbound' => ['Stripe' => ['scheme' => 'stripe', 'secret' => 's']]], 'webhooks.inbound names use lowercase letters, digits, "_", and "-".'];
        yield 'scheme' => [['inbound' => ['x' => ['scheme' => 'jwt', 'secret' => 's']]], 'webhooks.inbound.x.scheme must be one of xpsr, stripe, hmac, token.'];
        yield 'secret' => [['inbound' => ['x' => ['scheme' => 'stripe']]], 'webhooks.inbound.x.secret must be the provider\'s signing secret.'];
        yield 'hmac header' => [['inbound' => ['x' => ['scheme' => 'hmac', 'secret' => 's']]], 'webhooks.inbound.x.header must name the header the hmac signature is sent in.'];
        yield 'algorithm' => [['inbound' => ['x' => ['scheme' => 'hmac', 'secret' => 's', 'header' => 'X-Sig', 'algorithm' => 'rot13']]], 'webhooks.inbound.x.algorithm must be a hash_hmac_algos() name'];
        yield 'encoding' => [['inbound' => ['x' => ['scheme' => 'hmac', 'secret' => 's', 'header' => 'X-Sig', 'encoding' => 'binary']]], 'webhooks.inbound.x.encoding must be hex or base64.'];
        yield 'unknown inbound key' => [['inbound' => ['x' => ['scheme' => 'token', 'secret' => 's', 'header' => 'X', 'ttl' => 1]]], 'Unknown option webhooks.inbound.x.ttl.'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationIsRefused(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        WebhookOptions::fromArray($config);
    }
}
