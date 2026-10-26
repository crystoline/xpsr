<?php

declare(strict_types=1);

namespace Crystoline\Tests\Unit;

use Crystoline\Exception\ExceptionHandlerInterface;
use Crystoline\Http\HttpKernel;
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
