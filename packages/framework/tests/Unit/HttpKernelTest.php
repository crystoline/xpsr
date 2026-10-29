<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\Http\HttpKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Nyholm\Psr7\Factory\Psr17Factory as ResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory as ServerRequestFactory;

final class HttpKernelTest extends TestCase
{
    public function testMiddlewareOrderAndRequestStateArePreservedAcrossRequests(): void
    {
        $router = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $factory = new ResponseFactory();
                return $factory->createResponse()->withBody(
                    $factory->createStream(json_encode($request->getAttribute('trace', []), JSON_THROW_ON_ERROR)),
                );
            }
        };
        $middleware = static fn(string $label) => new class ($label) implements MiddlewareInterface {
            public function __construct(private readonly string $label) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $trace = $request->getAttribute('trace', []);
                $trace[] = $this->label;
                return $handler->handle($request->withAttribute('trace', $trace))
                    ->withAddedHeader('X-Return-Order', $this->label);
            }
        };
        $errors = $this->createMock(ExceptionHandlerInterface::class);
        $errors->expects(self::never())->method('render');
        $kernel = new HttpKernel($router, $errors, new ResponseFactory(), [$middleware('first'), $middleware('second')]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        $this->expectOutputString('');
        for ($i = 0; $i < 2; ++$i) {
            $response = $kernel->handle($request);
            self::assertSame(['first', 'second'], json_decode((string) $response->getBody(), true));
            self::assertSame(['second', 'first'], $response->getHeader('X-Return-Order'));
            self::assertNull($request->getAttribute('trace'));
        }
    }

    public function testMiddlewareCanReturnWithoutDispatching(): void
    {
        $router = $this->createMock(RequestHandlerInterface::class);
        $router->expects(self::never())->method('handle');
        $response = (new ResponseFactory())->createResponse(403);
        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects(self::once())->method('process')->willReturn($response);
        $errors = $this->createMock(ExceptionHandlerInterface::class);
        $errors->expects(self::never())->method('render');
        $kernel = new HttpKernel($router, $errors, new ResponseFactory(), [$middleware]);
        self::assertSame($response, $kernel->handle((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/')));
    }

    /** @return iterable<string, array{string, string, string}> request version, response version, expected */
    public static function protocolVersions(): iterable
    {
        yield 'HTTP/1.0 request' => ['1.0', '1.1', '1.0'];
        yield 'HTTP/1.1 request' => ['1.1', '1.1', '1.1'];
        yield 'HTTP/2 request keeps the response version' => ['2.0', '1.1', '1.1'];
        yield 'response already older' => ['1.1', '1.0', '1.0'];
        yield 'not a version' => ['INCLUDED', '1.1', '1.1'];
    }

    #[DataProvider('protocolVersions')]
    public function testResponseNeverUsesANewerHttpVersionThanTheRequest(string $requested, string $responseVersion, string $expected): void
    {
        $router = $this->createStub(RequestHandlerInterface::class);
        $router->method('handle')->willReturn((new ResponseFactory())->createResponse()->withProtocolVersion($responseVersion));
        $kernel = new HttpKernel($router, $this->createStub(ExceptionHandlerInterface::class), new ResponseFactory());
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/')->withProtocolVersion($requested);

        self::assertSame($expected, $kernel->handle($request)->getProtocolVersion());
    }

    public function testErrorResponsesAndHeadRequestsAlsoMatchTheRequestVersion(): void
    {
        $router = $this->createStub(RequestHandlerInterface::class);
        $router->method('handle')->willThrowException(new RuntimeException('Example failure'));
        $errors = $this->createStub(ExceptionHandlerInterface::class);
        $errors->method('render')->willReturn((new ResponseFactory())->createResponse(500)->withBody((new ResponseFactory())->createStream('error')));
        $request = (new ServerRequestFactory())->createServerRequest('HEAD', 'http://localhost/')->withProtocolVersion('1.0');

        $response = (new HttpKernel($router, $errors, new ResponseFactory()))->handle($request);
        self::assertSame('1.0', $response->getProtocolVersion());
        self::assertSame('', (string) $response->getBody());
    }

    public function testErrorBoundaryWrapsMiddleware(): void
    {
        $exception = new RuntimeException('Example failure');
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
        $response = (new ResponseFactory())->createResponse(500);
        $router = $this->createMock(RequestHandlerInterface::class);
        $router->expects(self::never())->method('handle');
        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects(self::once())->method('process')->willThrowException($exception);
        $errors = $this->createMock(ExceptionHandlerInterface::class);
        $errors->expects(self::once())->method('render')->with($request, $exception)->willReturn($response);
        self::assertSame($response, (new HttpKernel($router, $errors, new ResponseFactory(), [$middleware]))->handle($request));
    }
}
