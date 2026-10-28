<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\Contracts\Routing\RouterInterface;
use Crystoline\Xpsr\Exception\ExceptionHandlerInterface;
use Crystoline\Xpsr\Http\HttpKernel;
use Crystoline\Xpsr\Routing\HandlerResolver;
use Crystoline\Xpsr\Routing\RouteCollection;
use Crystoline\Xpsr\Routing\RouteDefinition;
use Crystoline\Xpsr\Routing\RouteDispatcher;
use Crystoline\Xpsr\Routing\RouteMatch;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use UnexpectedValueException;

final class RoutingErrorsTest extends TestCase
{
    public static function failureOrigins(): iterable
    {
        yield 'route middleware' => ['middleware'];
        yield 'controller' => ['controller'];
        yield 'invalid response' => ['response'];
    }

    #[DataProvider('failureOrigins')]
    public function testKernelRendersRouteFailuresAndSuppressesHeadErrorBody(string $origin): void
    {
        $factory = new Psr17Factory();
        $routes = new RouteCollection();
        $controller = match ($origin) {
            'controller' => static function (): never {
                throw new RuntimeException('Controller failure');
            },
            'response' => static fn() => 'not a response',
            default => static fn() => $factory->createResponse(),
        };
        $route = $routes->get('/failure', $controller);
        if ($origin === 'middleware') {
            $middleware = new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    throw new RuntimeException('Middleware failure');
                }
            };
            $route->middleware($middleware);
        }
        $request = $factory->createServerRequest('HEAD', 'http://localhost/failure');
        $errors = $this->createMock(ExceptionHandlerInterface::class);
        $errors->expects(self::once())->method('render')
            ->with($request, self::isInstanceOf($origin === 'response' ? UnexpectedValueException::class : RuntimeException::class))
            ->willReturn($factory->createResponse(500)->withBody($factory->createStream('Error details'))->withHeader('X-Error', 'rendered'));
        // Any router will do: this test is about the kernel's error boundary, not matching.
        $router = new class ($route) implements RouterInterface {
            public function __construct(private readonly RouteDefinition $route) {}

            public function match(ServerRequestInterface $request): RouteMatch
            {
                return RouteMatch::found($this->route);
            }
        };
        $kernel = new HttpKernel(new RouteDispatcher($router, new ServiceContainer(), $factory), $errors, $factory);
        $response = $kernel->handle($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('rendered', $response->getHeaderLine('X-Error'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testGlobalMiddlewareShortCircuitAlsoSuppressesHeadBody(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $errors = $this->createMock(ExceptionHandlerInterface::class);
        $errors->expects(self::never())->method('render');
        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects(self::once())->method('process')->willReturn(
            $factory->createResponse(403)->withHeader('Content-Length', '6')->withBody($factory->createStream('Denied')),
        );
        $kernel = new HttpKernel($handler, $errors, $factory, [$middleware]);
        $response = $kernel->handle($factory->createServerRequest('HEAD', 'http://localhost/'));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('6', $response->getHeaderLine('Content-Length'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testNonCallableServiceFailsClearly(): void
    {
        $container = new ServiceContainer();
        $container->instance('invalid', new \stdClass());
        $resolver = new HandlerResolver($container);
        $this->expectException(UnexpectedValueException::class);
        $resolver->handle('invalid', (new Psr17Factory())->createServerRequest('GET', '/'));
    }

    public function testControllerDependenciesAreAutowired(): void
    {
        $factory = new Psr17Factory();
        $controller = new class ($factory) {
            public function __construct(private readonly \Psr\Http\Message\ResponseFactoryInterface $responses) {}
            public function show(ServerRequestInterface $request): ResponseInterface
            {
                return $this->responses->createResponse(202);
            }
        };
        $container = new ServiceContainer();
        $container->instance(\Psr\Http\Message\ResponseFactoryInterface::class, $factory);
        $resolver = new HandlerResolver($container);
        self::assertSame(202, $resolver->handle([$controller::class, 'show'], $factory->createServerRequest('GET', '/'))->getStatusCode());
    }
}
