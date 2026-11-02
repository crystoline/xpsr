<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks\Inbound;

use Crystoline\Xpsr\Http\Exception\UnauthorizedHttpException;
use Crystoline\Xpsr\Webhooks\Signature;
use Crystoline\Xpsr\Webhooks\WebhookOptions;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Verifies the webhooks providers send, with the named verifiers of webhooks.inbound:
 * check() answers whether a request's signature is valid, verify() throws 401 when it is not.
 * VerifyWebhook::named($name) does the same as middleware.
 */
final class InboundSignatures
{
    public function __construct(private readonly WebhookOptions $options, private readonly ClockInterface $clock) {}

    /** @throws \InvalidArgumentException for a name webhooks.inbound does not have */
    public function check(string $name, ServerRequestInterface $request): bool
    {
        $verifier = $this->options->verifier($name);
        $given = $request->getHeaderLine($verifier->header);
        if ($given === '') {
            return false;
        }
        $body = self::body($request);

        return match ($verifier->scheme) {
            'xpsr', 'stripe' => Signature::verify($verifier->secret, $given, $body, $this->clock->now()->getTimestamp(), $verifier->tolerance),
            'hmac' => self::hmac($verifier, $given, $body),
            'token' => hash_equals($verifier->secret, $given),
            default => false,
        };
    }

    /** @throws UnauthorizedHttpException when the signature is missing or wrong */
    public function verify(string $name, ServerRequestInterface $request): void
    {
        if (!$this->check($name, $request)) {
            throw new UnauthorizedHttpException('Signature', "The {$name} webhook signature is missing or invalid.");
        }
    }

    private static function hmac(InboundVerifier $verifier, string $given, string $body): bool
    {
        if ($verifier->prefix !== '') {
            if (!str_starts_with($given, $verifier->prefix)) {
                return false;
            }
            $given = substr($given, strlen($verifier->prefix));
        }
        $expected = $verifier->encoding === 'base64'
            ? base64_encode(hash_hmac($verifier->algorithm, $body, $verifier->secret, true))
            : hash_hmac($verifier->algorithm, $body, $verifier->secret);

        return hash_equals($expected, $verifier->encoding === 'base64' ? trim($given) : strtolower(trim($given)));
    }

    /** The raw body, left rewound for whatever reads it next. */
    private static function body(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = $stream->getContents();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $body;
    }
}
