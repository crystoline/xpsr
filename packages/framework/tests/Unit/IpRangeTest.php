<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Http\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpRangeTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'exact IPv4' => ['192.168.1.10', ['192.168.1.10'], true];
        yield 'different IPv4' => ['192.168.1.11', ['192.168.1.10'], false];
        yield 'IPv4 /8' => ['10.200.3.4', ['10.0.0.0/8'], true];
        yield 'IPv4 outside /8' => ['11.0.0.1', ['10.0.0.0/8'], false];
        yield 'IPv4 /12 inside' => ['172.31.255.255', ['172.16.0.0/12'], true];
        yield 'IPv4 /12 outside' => ['172.32.0.0', ['172.16.0.0/12'], false];
        yield 'IPv4 /32' => ['10.0.0.1', ['10.0.0.1/32'], true];
        yield 'IPv4 /0 matches all IPv4' => ['8.8.8.8', ['0.0.0.0/0'], true];
        yield 'second range matches' => ['10.0.0.5', ['192.168.0.0/16', '10.0.0.0/24'], true];
        yield 'exact IPv6' => ['::1', ['::1'], true];
        yield 'IPv6 /8' => ['fd12:3456::1', ['fd00::/8'], true];
        yield 'IPv6 outside /8' => ['fe80::1', ['fd00::/8'], false];
        yield 'IPv6 /127 odd bits' => ['2001:db8::1', ['2001:db8::/127'], true];
        yield 'IPv6 /127 outside' => ['2001:db8::2', ['2001:db8::/127'], false];
        yield 'IPv4 never matches IPv6 range' => ['10.0.0.1', ['::/0'], false];
        yield 'IPv6 never matches IPv4 range' => ['::1', ['0.0.0.0/0'], false];
        yield 'prefix too long' => ['10.0.0.1', ['10.0.0.0/33'], false];
        yield 'non-numeric prefix' => ['10.0.0.1', ['10.0.0.0/x'], false];
        yield 'negative prefix' => ['10.0.0.1', ['10.0.0.0/-1'], false];
        yield 'invalid range' => ['10.0.0.1', ['not-an-ip'], false];
        yield 'invalid address' => ['not-an-ip', ['0.0.0.0/0'], false];
        yield 'empty list' => ['10.0.0.1', [], false];
    }

    /** @param list<string> $ranges */
    #[DataProvider('cases')]
    public function testMatches(string $address, array $ranges, bool $expected): void
    {
        self::assertSame($expected, IpRange::matches($address, $ranges));
    }
}
