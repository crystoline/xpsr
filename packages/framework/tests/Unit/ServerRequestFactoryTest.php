<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Factory\ServerRequestFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ServerRequestFactoryTest extends TestCase
{
    /** @param list<string> $trusted */
    private function create(array $trusted, string $remote): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/account',
            'HTTP_HOST' => 'example.test',
            'REMOTE_ADDR' => $remote,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ];
        $headers = ['Host' => 'example.test', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9', 'Forwarded' => 'proto=https', 'Accept' => 'application/json'];

        return ServerRequestFactory::create(new ServerRequestCreator($factory, $factory, $factory, $factory), $trusted, $server, $headers);
    }

    public static function untrusted(): iterable
    {
        yield 'no proxies configured' => [[], '10.0.0.5'];
        yield 'other address' => [['10.0.0.1'], '10.0.0.5'];
        yield 'outside range' => [['10.1.0.0/16'], '10.0.0.5'];
    }

    #[DataProvider('untrusted')]
    public function testForwardingHeadersFromUntrustedClientsAreIgnored(array $trusted, string $remote): void
    {
        $request = $this->create($trusted, $remote);

        self::assertSame('http', $request->getUri()->getScheme(), 'A client cannot claim HTTPS.');
        self::assertFalse($request->hasHeader('X-Forwarded-Proto'));
        self::assertFalse($request->hasHeader('X-Forwarded-For'));
        self::assertFalse($request->hasHeader('Forwarded'));
        self::assertArrayNotHasKey('HTTP_X_FORWARDED_PROTO', $request->getServerParams());
        self::assertSame('application/json', $request->getHeaderLine('Accept'), 'Other headers are kept.');
        self::assertSame('example.test', $request->getUri()->getHost());
    }

    public static function trusted(): iterable
    {
        yield 'exact address' => [['10.0.0.5'], '10.0.0.5'];
        yield 'CIDR range' => [['10.0.0.0/8'], '10.0.0.5'];
        yield 'IPv6 range' => [['fd00::/8'], 'fd00::1'];
    }

    #[DataProvider('trusted')]
    public function testForwardingHeadersFromTrustedProxiesAreUsed(array $trusted, string $remote): void
    {
        $request = $this->create($trusted, $remote);

        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('203.0.113.9', $request->getHeaderLine('X-Forwarded-For'));
    }
}
