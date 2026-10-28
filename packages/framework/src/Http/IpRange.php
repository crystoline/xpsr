<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http;

/**
 * Matches IPv4 and IPv6 addresses against exact addresses and CIDR ranges, such as
 * 10.0.0.0/8 or fd00::/8. Invalid ranges never match. An IPv4 address does not match an IPv6
 * range, and the reverse; list both forms if a proxy may appear as either.
 */
final class IpRange
{
    /** @param list<string> $ranges */
    public static function matches(string $address, array $ranges): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$network, $prefix] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $networkBinary = @inet_pton(trim($network));
            if ($networkBinary === false || strlen($networkBinary) !== strlen($binary)) {
                continue;
            }
            $maximum = strlen($binary) * 8;
            if ($prefix === null) {
                $bits = $maximum;
            } elseif (ctype_digit($prefix) && (int) $prefix <= $maximum) {
                $bits = (int) $prefix;
            } else {
                continue;
            }
            $bytes = intdiv($bits, 8);
            if (substr($binary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
                continue;
            }
            $remainder = $bits % 8;
            if ($remainder === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
