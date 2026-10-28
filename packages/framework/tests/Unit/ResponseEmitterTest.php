<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Http\ResponseEmitter;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Crystoline\Xpsr\Tests\Fixtures\Http\ChunkedStream;

final class ResponseEmitterTest extends TestCase
{
    /** @var list<array{string, bool, int}> */
    private array $headers = [];

    private function emitter(int $chunkSize = 8192, ?string $outputStartedAt = null): ResponseEmitter
    {
        return new ResponseEmitter(
            $chunkSize,
            function (string $line, bool $replace, int $status): void {
                $this->headers[] = [$line, $replace, $status];
            },
            static fn(): ?string => $outputStartedAt,
        );
    }

    /** @return string the emitted body */
    private function emit(ResponseInterface $response, int $chunkSize = 8192): string
    {
        ob_start();
        try {
            $this->emitter($chunkSize)->emit($response);
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }

    public function testHeadersKeepOrderAndRepeatedValuesThenTheStatusLineComesLast(): void
    {
        $response = (new Response(201, [], 'created', '1.1', 'Made'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Multi', ['a', 'b'])
            ->withAddedHeader('Set-Cookie', 'a=1; HttpOnly')
            ->withAddedHeader('Set-Cookie', 'b=2; Secure')
            ->withHeader('Location', '/items/1');

        self::assertSame('created', $this->emit($response));
        self::assertSame([
            ['Content-Type: application/json', true, 201],
            ['X-Multi: a', true, 201],
            ['X-Multi: b', false, 201],
            // Never replace earlier cookies, including ones set by PHP itself, for example session cookies.
            ['Set-Cookie: a=1; HttpOnly', false, 201],
            ['Set-Cookie: b=2; Secure', false, 201],
            // The explicit status stops PHP from turning a Location header into a 302.
            ['Location: /items/1', true, 201],
            ['HTTP/1.1 201 Made', true, 201],
        ], $this->headers);
    }

    public static function statusLines(): iterable
    {
        yield 'standard reason' => [new Response(404), 'HTTP/1.1 404 Not Found'];
        yield 'HTTP/1.0' => [(new Response(200))->withProtocolVersion('1.0'), 'HTTP/1.0 200 OK'];
        yield 'HTTP/2' => [(new Response(200))->withProtocolVersion('2'), 'HTTP/2 200 OK'];
        yield 'custom reason' => [new Response(299, [], null, '1.1', 'Custom Thing'), 'HTTP/1.1 299 Custom Thing'];
        yield 'no reason, no trailing space' => [new Response(299), 'HTTP/1.1 299'];
    }

    #[DataProvider('statusLines')]
    public function testStatusLine(ResponseInterface $response, string $expected): void
    {
        $this->emit($response);
        self::assertSame([$expected, true, $response->getStatusCode()], end($this->headers));
    }

    public static function bodylessStatuses(): iterable
    {
        foreach ([100, 101, 204, 205, 304] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('bodylessStatuses')]
    public function testBodylessStatusesSendNoBodyButKeepHeaders(int $status): void
    {
        $response = (new Response($status, ['ETag' => '"v1"'], 'must not be sent'));
        self::assertSame('', $this->emit($response));
        self::assertSame(['ETag: "v1"', true, $status], $this->headers[0]);
    }

    public function testSeekableBodyIsRewoundAndReadInChunks(): void
    {
        $body = (new Psr17Factory())->createStream(str_repeat('abcdefghij', 10));
        $body->read(30);
        self::assertSame(str_repeat('abcdefghij', 10), $this->emit(new Response(200, [], $body), 7));
    }

    public function testEmptyBodySendsNothing(): void
    {
        self::assertSame('', $this->emit(new Response(200)));
        self::assertSame(['HTTP/1.1 200 OK', true, 200], $this->headers[0]);
    }

    public function testContentLengthLimitsTheBody(): void
    {
        self::assertSame('0123', $this->emit(new Response(200, ['Content-Length' => '4'], '0123456789')));
        self::assertSame('', $this->emit(new Response(200, ['Content-Length' => '0'], '0123456789')));
        // An invalid Content-Length is ignored rather than trusted.
        self::assertSame('0123456789', $this->emit(new Response(200, ['Content-Length' => 'abc'], '0123456789')));
    }

    public function testStreamedBodyOfUnknownSizeIsSentChunkByChunk(): void
    {
        $stream = new ChunkedStream(['first ', 'second ', 'third']);
        self::assertSame('first second third', $this->emit(new Response(200, [], $stream), 4));
        self::assertSame(['firs', 't ', 'seco', 'nd ', 'thir', 'd'], $stream->reads, 'Each read is limited to the chunk size.');
    }

    public function testStreamedBodyRespectsContentLength(): void
    {
        $stream = new ChunkedStream(['abc', 'def', 'ghi']);
        self::assertSame('abcde', $this->emit(new Response(200, ['Content-Length' => '5'], $stream)));
        self::assertSame(['abc', 'de'], $stream->reads, 'Reading stops once the declared length is sent.');
    }

    public function testRefusesToEmitAfterOutputStarted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('output already started (/app/view.php:12)');
        try {
            $this->emitter(outputStartedAt: '/app/view.php:12')->emit(new Response(200, [], 'body'));
        } finally {
            self::assertSame([], $this->headers);
        }
    }

    public function testRejectsInvalidChunkSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResponseEmitter(0);
    }
}
