<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Inbound;

use Crystoline\Xpsr\Webhooks\Period;
use InvalidArgumentException;

/**
 * One named entry of webhooks.inbound, validated: how a provider signs the webhooks it sends.
 *
 *   scheme     xpsr (this package's own signature: t=<timestamp>,v1=<hmac>), stripe (the same
 *              form in the Stripe-Signature header), hmac (a header holding an HMAC of the raw
 *              body), or token (a header that must equal the secret)
 *   secret     the shared secret
 *   header     the header to read; hmac and token need it, xpsr and stripe have defaults
 *   algorithm  for hmac: sha256 (the default), sha512, or any hash_hmac_algos() name
 *   encoding   for hmac: hex (the default) or base64
 *   prefix     for hmac: text before the digest in the header, such as "sha256="
 *   tolerance  for xpsr and stripe: how old a signed timestamp may be (default: webhooks.tolerance)
 */
final class InboundVerifier
{
    public const SCHEMES = ['xpsr', 'stripe', 'hmac', 'token'];
    private const KEYS = ['scheme', 'secret', 'header', 'algorithm', 'encoding', 'prefix', 'tolerance'];

    public function __construct(
        public readonly string $name,
        public readonly string $scheme,
        public readonly string $secret,
        public readonly string $header,
        public readonly string $algorithm = 'sha256',
        public readonly string $encoding = 'hex',
        public readonly string $prefix = '',
        public readonly int $tolerance = 300,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(string $name, array $config, string $headerPrefix, int $tolerance): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("Unknown option webhooks.inbound.{$name}.{$key}. Known options: " . implode(', ', self::KEYS) . '.');
            }
        }
        $scheme = $config['scheme'] ?? null;
        if (!is_string($scheme) || !in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException("webhooks.inbound.{$name}.scheme must be one of " . implode(', ', self::SCHEMES) . '.');
        }
        $secret = $config['secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            throw new InvalidArgumentException("webhooks.inbound.{$name}.secret must be the provider's signing secret.");
        }
        $header = $config['header'] ?? match ($scheme) {
            'xpsr' => "{$headerPrefix}-Signature",
            'stripe' => 'Stripe-Signature',
            default => null,
        };
        if (!is_string($header) || !preg_match('/\A[A-Za-z0-9-]+\z/', $header)) {
            throw new InvalidArgumentException("webhooks.inbound.{$name}.header must name the header the {$scheme} signature is sent in.");
        }
        $algorithm = $config['algorithm'] ?? 'sha256';
        if (!is_string($algorithm) || !in_array(strtolower($algorithm), hash_hmac_algos(), true)) {
            throw new InvalidArgumentException("webhooks.inbound.{$name}.algorithm must be a hash_hmac_algos() name such as sha256 or sha512.");
        }
        $encoding = $config['encoding'] ?? 'hex';
        if (!in_array($encoding, ['hex', 'base64'], true)) {
            throw new InvalidArgumentException("webhooks.inbound.{$name}.encoding must be hex or base64.");
        }
        $prefix = $config['prefix'] ?? '';
        if (!is_string($prefix)) {
            throw new InvalidArgumentException("webhooks.inbound.{$name}.prefix must be text, such as \"sha256=\".");
        }

        return new self(
            $name,
            $scheme,
            $secret,
            $header,
            strtolower($algorithm),
            $encoding,
            $prefix,
            isset($config['tolerance']) ? Period::seconds($config['tolerance'], "webhooks.inbound.{$name}.tolerance") : $tolerance,
        );
    }
}
