<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Exception\ExceptionHandler;
use Crystoline\Xpsr\Http\Exception\NotFoundHttpException;
use Crystoline\Xpsr\Http\Middleware\CorsMiddleware;
use Crystoline\Xpsr\Http\Middleware\CorsOptions;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class CorsMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;
    private int $handled = 0;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testPreflightFromAnAllowedOriginIsAnsweredWithoutReachingTheApplication(): void
    {
        $response = $this->through(['allowed_origins' => ['https://app.example.com']], $this->request('OPTIONS', 'https://app.example.com', [
            'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'content-type, x-custom',
        ]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->handled);
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, HEAD, POST, PUT, PATCH, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization, X-Requested-With', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testAWildcardHeaderListEchoesTheRequestedHeaders(): void
    {
        $response = $this->through(['allowed_origins' => '*', 'allowed_headers' => ['*'], 'max_age' => 0], $this->request('OPTIONS', 'https://any.test', [
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-Custom, Content-Type',
        ]));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('X-Custom, Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertFalse($response->hasHeader('Access-Control-Max-Age'));
    }

    public function testActualRequestsFromAllowedOriginsGetTheResponseHeaders(): void
    {
        $response = $this->through(
            ['allowed_origins' => ['https://app.example.com'], 'exposed_headers' => ['X-Total-Count'], 'allow_credentials' => true],
            $this->request('GET', 'https://app.example.com'),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->handled);
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('X-Total-Count', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'), 'An existing Vary header is kept.');
    }

    public function testAnyOriginWithCredentialsEchoesTheOrigin(): void
    {
        $response = $this->through(['allowed_origins' => ['*'], 'allow_credentials' => true], $this->request('GET', 'https://other.test'));
        self::assertSame('https://other.test', $response->getHeaderLine('Access-Control-Allow-Origin'), '"*" cannot be used with credentials.');
    }

    public function testSubdomainPatternsMatchOnlySubdomainsWithTheSameSchemeAndPort(): void
    {
        $options = CorsOptions::fromArray(['allowed_origins' => ['https://*.example.com', 'http://localhost:5173']]);
        self::assertTrue($options->allows('https://app.example.com'));
        self::assertTrue($options->allows('https://a.b.example.com'));
        self::assertTrue($options->allows('HTTPS://App.Example.com'));
        self::assertTrue($options->allows('http://localhost:5173'));
        self::assertFalse($options->allows('https://example.com'), 'The pattern needs a subdomain.');
        self::assertFalse($options->allows('http://app.example.com'));
        self::assertFalse($options->allows('https://app.example.com:8443'));
        self::assertFalse($options->allows('https://evilexample.com'));
        self::assertFalse($options->allows('https://app.example.com.evil.test'));
        self::assertFalse($options->allows('http://localhost:3000'));
        self::assertFalse($options->allows('null'));
    }

    public function testOtherOriginsAndRequestsWithoutOriginPassThrough(): void
    {
        $options = ['allowed_origins' => ['https://app.example.com']];
        $other = $this->through($options, $this->request('GET', 'https://evil.test'));
        self::assertFalse($other->hasHeader('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Origin', $other->getHeaderLine('Vary'));

        $preflight = $this->through($options, $this->request('OPTIONS', 'https://evil.test', ['Access-Control-Request-Method' => 'DELETE']));
        self::assertSame(200, $preflight->getStatusCode(), 'A preflight from another origin reaches the application, which answers OPTIONS itself.');
        self::assertFalse($preflight->hasHeader('Access-Control-Allow-Origin'));

        $plain = $this->through($options, $this->factory->createServerRequest('GET', '/'));
        self::assertFalse($plain->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testWithoutAllowedOriginsNothingChanges(): void
    {
        $response = $this->through([], $this->request('GET', 'https://app.example.com'));
        self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testErrorsFromLaterLayersAreRenderedWithTheCorsHeaders(): void
    {
        $middleware = $this->middleware(['allowed_origins' => ['https://app.example.com']]);
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new NotFoundHttpException();
            }
        };
        $response = $middleware->process($this->request('GET', 'https://app.example.com')->withHeader('Accept', 'application/json'), $failing);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(['message' => 'Not Found'], json_decode((string) $response->getBody(), true));

        $this->expectException(NotFoundHttpException::class);
        $middleware->process($this->request('GET', 'https://evil.test'), $failing);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown option' => [['origins' => ['*']], 'Unknown option cors.origins.'];
        yield 'origin with a path' => [['allowed_origins' => ['https://app.example.com/path']], 'is not an origin'];
        yield 'origin without a scheme' => [['allowed_origins' => ['app.example.com']], 'is not an origin'];
        yield 'wildcard in the middle' => [['allowed_origins' => ['https://app.*.com']], 'is not an origin'];
        yield 'origins not a list' => [['allowed_origins' => ['a' => 'https://app.example.com']], 'cors.allowed_origins must be a list of strings.'];
        yield 'method with a space' => [['allowed_methods' => ['GET POST']], 'cors.allowed_methods: "GET POST" is not a valid name.'];
        yield 'header with a colon' => [['allowed_headers' => ['X-A:B']], 'cors.allowed_headers: "X-A:B" is not a valid name.'];
        yield 'credentials' => [['allow_credentials' => 'yes'], 'cors.allow_credentials must be true or false.'];
        yield 'max age' => [['max_age' => -1], 'cors.max_age must be a number of seconds, 0 or more.'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsNameTheSetting(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        CorsOptions::fromArray($options);
    }

    public function testCommaSeparatedOriginsAreAccepted(): void
    {
        $options = CorsOptions::fromArray(['allowed_origins' => ' https://a.test , https://b.test/ ,']);
        self::assertTrue($options->allows('https://a.test'));
        self::assertTrue($options->allows('https://b.test'));
        self::assertFalse(CorsOptions::fromArray(['allowed_origins' => ''])->enabled());
    }

    /** @param array<string, mixed> $options */
    private function through(array $options, ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class (function (): ResponseInterface {
            ++$this->handled;

            return $this->factory->createResponse(200)->withHeader('Vary', 'Accept-Encoding');
        }) implements RequestHandlerInterface {
            /** @param \Closure(): ResponseInterface $respond */
            public function __construct(private readonly \Closure $respond) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->respond)();
            }
        };

        return $this->middleware($options)->process($request, $handler);
    }

    /** @param array<string, mixed> $options */
    private function middleware(array $options): CorsMiddleware
    {
        return new CorsMiddleware(
            CorsOptions::fromArray($options),
            $this->factory,
            new ExceptionHandler($this->factory, $this->factory, new NullLogger(), false),
        );
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $origin, array $headers = []): ServerRequestInterface
    {
        $request = $this->factory->createServerRequest($method, 'https://api.example.com/items')->withHeader('Origin', $origin);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
