<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

/**
 * Version 7 UUIDs: time-ordered, so new rows are added at the end of the table's index.
 *
 * @internal
 */
final class Uuid
{
    public static function next(): string
    {
        $hex = str_pad(dechex((int) floor(microtime(true) * 1000)), 12, '0', STR_PAD_LEFT) . bin2hex(random_bytes(10));
        $hex[12] = '7';
        $hex[16] = dechex(8 | ((int) hexdec($hex[16]) & 3));

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    public static function is(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) === 1;
    }
}
