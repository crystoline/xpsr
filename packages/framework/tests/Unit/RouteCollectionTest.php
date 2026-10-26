<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Routing\RouteCollection;
use Crystoline\Routing\RoutePattern;
use Crystoline\Routing\UrlGenerator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouteCollectionTest extends TestCase
{
    public static function invalidDefinitions(): iterable
    {
        yield 'duplicate names' => [static function (RouteCollection $r): void {
            $r->get('/a', 'handler')->name('same');
            $r->get('/b', 'handler')->name('same');
        }];
        yield 'duplicate method and path' => [static function (RouteCollection $r): void {
            $r->get('/a', 'handler');
            $r->get('/a', 'handler');
        }];
        yield 'equivalent placeholders' => [static function (RouteCollection $r): void {
            $r->get('/{id}', 'handler');
            $r->get('/{slug}', 'handler');
        }];
        yield 'inline regex' => [static fn(RouteCollection $r) => $r->get('/{id:\d+}', 'handler')];
        yield 'optional segment' => [static fn(RouteCollection $r) => $r->get('/a[/{id}]', 'handler')];
        yield 'partial segment' => [static fn(RouteCollection $r) => $r->get('/file-{id}', 'handler')];
        yield 'duplicate placeholder' => [static fn(RouteCollection $r) => $r->get('/{id}/{id}', 'handler')];
        yield 'reserved placeholder' => [static fn(RouteCollection $r) => $r->get('/{_route}', 'handler')];
        yield 'network path' => [static fn(RouteCollection $r) => $r->get('//example.com', 'handler')];
        yield 'relative path' => [static fn(RouteCollection $r) => $r->get('hello', 'handler')];
        yield 'dot segment' => [static fn(RouteCollection $r) => $r->get('/a/../b', 'handler')];
        yield 'no methods' => [static fn(RouteCollection $r) => $r->add([], '/', 'handler')];
        yield 'wildcard method' => [static fn(RouteCollection $r) => $r->add(['*'], '/', 'handler')];
        yield 'invalid handler' => [static fn(RouteCollection $r) => $r->get('/', 123)];
        yield 'unknown constraint' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->where('other', '\d+')];
        yield 'capturing regex' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->where('id', '(a|b)')];
        yield 'slash regex' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->where('id', '.+')];
        yield 'bad character range' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->where('id', '[z-a]+')];
        yield 'range includes encoded punctuation' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->where('id', '[A-z]+')];
        yield 'unknown default' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->defaults(['other' => 'x'])];
        yield 'constraint invalidates default' => [static fn(RouteCollection $r) => $r->get('/{id}', 'handler')->defaults(['id' => 'abc'])->where('id', '\d+')];
    }

    #[DataProvider('invalidDefinitions')]
    public function testRejectsNonPortableDefinitions(callable $configure): void
    {
        $routes = new RouteCollection();
        $this->expectException(InvalidArgumentException::class);
        $configure($routes);
        $routes->freeze();
    }

    public function testUrlDefaultsQueryEncodingAndFrozenDefinitions(): void
    {
        $routes = new RouteCollection();
        $route = $routes->get('/users/{id}', 'handler')->name('user')->where('id', '\d+')->defaults(['id' => 42]);
        $urls = new UrlGenerator($routes);
        self::assertSame('/users/42', $urls->generate('user'));
        self::assertSame('/users/0?q=a%20b&tags%5B0%5D=x%2Fy', $urls->generate('user', ['id' => 0], ['q' => 'a b', 'tags' => ['x/y']]));
        $this->expectException(LogicException::class);
        $route->name('changed');
    }

    public function testFrozenCollectionRejectsNewRoutes(): void
    {
        $routes = new RouteCollection();
        $routes->freeze();
        $this->expectException(LogicException::class);
        $routes->get('/', 'handler');
    }

    public static function invalidUrls(): iterable
    {
        yield 'missing' => [[]];
        yield 'extra' => [['id' => 42, 'extra' => 'x']];
        yield 'constraint' => [['id' => 'word']];
        yield 'slash' => [['id' => '4/2']];
        yield 'backslash' => [['id' => '4\\2']];
        yield 'null' => [['id' => null]];
        yield 'boolean' => [['id' => false]];
        yield 'array' => [['id' => [42]]];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlParametersFailClearly(array $parameters): void
    {
        $routes = new RouteCollection();
        $routes->get('/{id}', 'handler')->where('id', '\d+')->name('test');
        $urls = new UrlGenerator($routes);
        $this->expectException(InvalidArgumentException::class);
        $urls->generate('test', $parameters);
    }

    public function testUnknownRouteNameFailsClearly(): void
    {
        $urls = new UrlGenerator(new RouteCollection());
        $this->expectException(InvalidArgumentException::class);
        $urls->generate('unknown');
    }

    public function testGroupContextRestoredWhenCallbackThrows(): void
    {
        $routes = new RouteCollection();
        try {
            $routes->group('/prefix', static function (): never {
                throw new RuntimeException('Stop');
            }, namePrefix: 'prefix.');
        } catch (RuntimeException) {
        }
        $routes->get('/outside', 'handler')->name('outside');
        self::assertSame('/outside', (new UrlGenerator($routes))->generate('outside'));
    }

    public function testMalformedEncodingAndEmptyPathNormalization(): void
    {
        self::assertNull(RoutePattern::normalizePath('/bad%'));
        self::assertNull(RoutePattern::normalizePath('/bad%xy'));
        self::assertSame('/', RoutePattern::normalizePath(''));
        self::assertSame('/%252F', RoutePattern::normalizePath('/%252F'));
    }
}
