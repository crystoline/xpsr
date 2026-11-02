<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

/**
 * Version 7 UUIDs: time-ordered, so new rows are added at the end of the table's index, and
 * ordering by id orders by creation. Within a process, two UUIDs made in the same millisecond
 * keep their order too: the twelve bits after the version count up, so rows created together
 * sort the way they were created.
 *
 * @internal
 */
final class Uuid
{
    private static int $milliseconds = 0;
    private static int $sequence = 0;

    public static function next(): string
    {
        $now = (int) floor(microtime(true) * 1000);
        if ($now > self::$milliseconds) {
            self::$milliseconds = $now;
            // Starts low, so a burst of UUIDs in one millisecond does not run out of sequence.
            self::$sequence = random_int(0, 0x7ff);
        } elseif (++self::$sequence > 0xfff) {
            // The same millisecond again, or the clock went back: stay ahead of the last one.
            self::$milliseconds++;
            self::$sequence = random_int(0, 0x7ff);
        }
        $hex = str_pad(dechex(self::$milliseconds), 12, '0', STR_PAD_LEFT)
            . '7' . str_pad(dechex(self::$sequence), 3, '0', STR_PAD_LEFT)
            . bin2hex(random_bytes(8));
        $hex[16] = dechex(8 | ((int) hexdec($hex[16]) & 3));

        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    public static function is(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) === 1;
    }
}
