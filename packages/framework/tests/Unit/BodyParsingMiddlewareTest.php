<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Http\Middleware\BodyParsingMiddleware;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Crystoline\Xpsr\Http\Exception\HttpExceptionInterface;
use Crystoline\Xpsr\Tests\Fixtures\Http\ChunkedStream;

final class BodyParsingMiddlewareTest extends TestCase
{
    private ?ServerRequestInterface $received = null;

    private function process(
        string $method,
        string $contentType,
        string|StreamInterface $body,
        ?array $parsed = null,
        int $limit = 1_048_576,
    ): ServerRequestInterface {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://localhost/')
            ->withBody(is_string($body) ? $factory->createStream($body) : $body)
            ->withParsedBody($parsed);
        if ($contentType !== '') {
            $request = $request->withHeader('Content-Type', $contentType);
        }
        $handler = new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly BodyParsingMiddlewareTest $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->receive($request);

                return new Response(204);
            }
        };
        (new BodyParsingMiddleware($limit))->process($request, $handler);
        self::assertNotNull($this->received);

        return $this->received;
    }

    public function receive(ServerRequestInterface $request): void
    {
        $this->received = $request;
    }

    public static function jsonTypes(): iterable
    {
        yield 'json' => ['application/json'];
        yield 'json with charset' => ['application/json; charset=utf-8'];
        yield 'upper case' => ['Application/JSON'];
        yield 'structured suffix' => ['application/problem+json'];
    }

    #[DataProvider('jsonTypes')]
    public function testJsonBodiesBecomeArraysAndTheRawBodyStaysReadable(string $type): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request = $this->process($method, $type, '{"name":"Ada","tags":["a","b"],"id":12345678901234567890}');
            self::assertSame(['name' => 'Ada', 'tags' => ['a', 'b'], 'id' => '12345678901234567890'], $request->getParsedBody(), $method);
            self::assertSame('{"name":"Ada"', substr((string) $request->getBody(), 0, 13));
        }
        self::assertSame([1, 2], $this->process('POST', $type, '[1,2]')->getParsedBody());
    }

    public static function invalidJson(): iterable
    {
        yield 'syntax error' => ['{"name":', 'Malformed JSON request body'];
        yield 'scalar' => ['"text"', 'must be an object or an array'];
        yield 'number' => ['42', 'must be an object or an array'];
        yield 'null' => ['null', 'must be an object or an array'];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsABadRequest(string $body, string $message): void
    {
        try {
            $this->process('POST', 'application/json', $body);
            self::fail('Invalid JSON must be rejected.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertNull($this->received, 'The handler must not run.');
    }

    public function testEmptyJsonBodyLeavesParsedBodyUnset(): void
    {
        self::assertNull($this->process('POST', 'application/json', '')->getParsedBody());
    }

    public function testFormBodiesAreParsedForMethodsPhpDoesNotHandle(): void
    {
        $request = $this->process('PUT', 'application/x-www-form-urlencoded', 'name=Ada&tags%5B%5D=a&tags%5B%5D=b');
        self::assertSame(['name' => 'Ada', 'tags' => ['a', 'b']], $request->getParsedBody());
        // PHP already parsed POST forms into $_POST; that result is kept.
        self::assertSame(['from' => 'php'], $this->process('POST', 'application/x-www-form-urlencoded', 'name=Ada', ['from' => 'php'])->getParsedBody());
    }

    public function testOtherContentTypesAreUntouched(): void
    {
        foreach (['', 'text/plain', 'multipart/form-data; boundary=x', 'application/xml', 'text/json+other'] as $type) {
            self::assertSame(['kept' => true], $this->process('PATCH', $type, '{"a":1}', ['kept' => true])->getParsedBody(), $type);
        }
    }

    public function testBodiesOverTheLimitArePayloadTooLarge(): void
    {
        self::assertSame(['a' => 1], $this->process('POST', 'application/json', '{"a":1}', limit: 7)->getParsedBody());
        foreach (['known size' => '{"a":12}', 'unknown size' => new ChunkedStream(['{"a":', '12}'])] as $label => $body) {
            try {
                $this->process('POST', 'application/json', $body, limit: 7);
                self::fail("An oversized body ({$label}) must be rejected.");
            } catch (HttpExceptionInterface $exception) {
                self::assertSame(413, $exception->getStatusCode(), $label);
            }
        }
    }

    public function testRejectsInvalidLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BodyParsingMiddleware(0);
    }
}
