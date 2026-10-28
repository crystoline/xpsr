<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Http\ClientIp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    /** @return iterable<string, array{string|null, string, list<string>, string|null}> */
    public static function requests(): iterable
    {
        yield 'no proxies configured' => ['203.0.113.7', '198.51.100.1', [], '203.0.113.7'];
        yield 'not from a trusted proxy' => ['203.0.113.7', '198.51.100.1', ['10.0.0.0/8'], '203.0.113.7'];
        yield 'behind one trusted proxy' => ['10.0.0.2', '198.51.100.1', ['10.0.0.0/8'], '198.51.100.1'];
        yield 'behind two trusted proxies' => ['10.0.0.2', '198.51.100.1, 10.0.0.9', ['10.0.0.0/8'], '198.51.100.1'];
        yield 'a spoofed entry stays left of the real client' => ['10.0.0.2', '1.2.3.4, 198.51.100.1', ['10.0.0.0/8'], '198.51.100.1'];
        yield 'IPv6 client' => ['10.0.0.2', '2001:db8::5', ['10.0.0.0/8'], '2001:db8::5'];
        yield 'malformed header falls back to the proxy' => ['10.0.0.2', 'not-an-ip', ['10.0.0.0/8'], '10.0.0.2'];
        yield 'only proxies in the chain' => ['10.0.0.2', '10.0.0.3', ['10.0.0.0/8'], '10.0.0.2'];
        yield 'no header from a trusted proxy' => ['10.0.0.2', '', ['10.0.0.0/8'], '10.0.0.2'];
        yield 'no remote address' => [null, '198.51.100.1', [], null];
    }

    /** @param list<string> $proxies */
    #[DataProvider('requests')]
    public function testTheClientIsTheFirstUntrustedAddressFromTheRight(?string $remote, string $forwarded, array $proxies, ?string $expected): void
    {
        $request = new ServerRequest('GET', '/', $forwarded === '' ? [] : ['X-Forwarded-For' => $forwarded], null, '1.1', $remote === null ? [] : ['REMOTE_ADDR' => $remote]);
        self::assertSame($expected, ClientIp::of($request, $proxies));
    }
}
