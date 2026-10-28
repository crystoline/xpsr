<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Http\Cookie;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CookieTest extends TestCase
{
    public function testDefaultsAreHttpOnlySecureAndLax(): void
    {
        self::assertSame('theme=dark; Path=/; Secure; HttpOnly; SameSite=Lax', (new Cookie('theme', 'dark'))->headerValue());
    }

    public function testEveryAttributeIsWritten(): void
    {
        $cookie = new Cookie('prefs', 'a b;c=d+é', maxAge: 3600, path: '/app', domain: 'example.com', secure: false, httpOnly: false, sameSite: 'Strict');
        self::assertSame('prefs=a%20b%3Bc%3Dd%2B%C3%A9; Max-Age=3600; Path=/app; Domain=example.com; SameSite=Strict', $cookie->headerValue());
        self::assertSame('a b;c=d+é', urldecode('a%20b%3Bc%3Dd%2B%C3%A9'), 'PHP decodes the value back unchanged.');
    }

    public function testForgettingSendsAnExpiredCookie(): void
    {
        self::assertSame('theme=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax', Cookie::forget('theme')->headerValue());
    }

    public function testCookiesAreAddedBesideOthers(): void
    {
        $response = (new Cookie('b', '2'))->addTo((new Cookie('a', '1'))->addTo(new Response()));
        self::assertCount(2, $response->getHeader('Set-Cookie'));
    }

    /** @return iterable<string, array{callable(): Cookie, string}> */
    public static function invalid(): iterable
    {
        yield 'name with a space' => [static fn() => new Cookie('my cookie'), 'cookie name'];
        yield 'name with =' => [static fn() => new Cookie('a=b'), 'cookie name'];
        yield 'negative max age' => [static fn() => new Cookie('a', maxAge: -1), 'max age'];
        yield 'path with ;' => [static fn() => new Cookie('a', path: '/; Domain=evil.test'), 'path or domain'];
        yield 'domain with a newline' => [static fn() => new Cookie('a', domain: "example.com\r\nX: y"), 'path or domain'];
        yield 'unknown SameSite' => [static fn() => new Cookie('a', sameSite: 'lax'), 'SameSite'];
        yield 'SameSite None without Secure' => [static fn() => new Cookie('a', secure: false, sameSite: 'None'), 'not Secure'];
    }

    /** @param callable(): Cookie $create */
    #[DataProvider('invalid')]
    public function testInvalidCookiesAreRefused(callable $create, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $create();
    }
}
