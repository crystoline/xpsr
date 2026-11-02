<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Webhooks;

/**
 * The signature of a delivery: "t=<unix time>,v1=<hex HMAC-SHA256 of "<unix time>.<body>">",
 * the form Stripe made familiar. The timestamp is signed with the body, so a captured delivery
 * cannot be replayed once it is older than the receiver's tolerance. A receiver verifies with
 * the endpoint's secret; several v1 values are accepted, for a secret being rotated.
 */
final class Signature
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return "t={$timestamp},v1=" . self::digest($secret, $timestamp, $body);
    }

    public static function digest(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /** @param int $tolerance how many seconds old, or ahead, the signed timestamp may be */
    public static function verify(string $secret, string $header, string $body, int $now, int $tolerance): bool
    {
        $timestamp = null;
        $digests = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $digests[] = strtolower($value);
            }
        }
        if ($timestamp === null || $digests === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = self::digest($secret, $timestamp, $body);
        foreach ($digests as $digest) {
            if (hash_equals($expected, $digest)) {
                return true;
            }
        }

        return false;
    }
}
