<?php

declare(strict_types=1);

namespace Crystoline\Testing;

use Crystoline\Contracts\Routing\RouterInterface;
use Crystoline\Exception\ExceptionHandler;
use Crystoline\Http\HttpKernel;
use Crystoline\Routing\RouteCollection;
use Crystoline\Routing\RouteDefinition;
use Crystoline\Routing\RouteDispatcher;
use Crystoline\Routing\UrlGenerator;
use Crystoline\ServiceContainer\ServiceContainer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The behavior every XPSR router adapter must provide.
 *
 * An adapter package extends this class in its own tests and implements createRouter(). The
 * cases cover matching precedence, constraints, decoded parameters, URL encoding round trips,
 * HEAD and OPTIONS policy, 404/405 rendering, nested groups and middleware order, short
 * circuits, and PSR-15 handler services.
 */
abstract class RouterContractTestCase extends TestCase
{
    abstract protected function createRouter(RouteCollection $routes): RouterInterface;

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, 'http://localhost' . $path);
    }

    private function responder(string $body): callable
    {
        return static fn(ServerRequestInterface $request) => (new Psr17Factory())->createResponse()
            ->withBody((new Psr17Factory())->createStream($body))
            ->withHeader('X-Method', $request->getMethod());
    }

    private function kernel(RouterInterface $router, ?ServiceContainer $container = null): HttpKernel
    {
        $factory = new Psr17Factory();
        // 404 and 405 are rendered by the real handler; anything it would log (a server error) fails the test.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $errors = new ExceptionHandler($factory, $factory, $logger);
        return new HttpKernel(new RouteDispatcher($router, $container ?? new ServiceContainer(), $factory), $errors, $factory);
    }

    public function testMatchingPrecedenceConstraintsAndParameters(): void
    {
        $routes = new RouteCollection();
        $dynamic = $routes->get('/items/{slug}', $this->responder('dynamic'))->name('item');
        $static = $routes->get('/items/new', $this->responder('static'))->name('new');
        $numeric = $routes->get('/users/{id}', $this->responder('number'))->where('id', '\d+');
        $fallback = $routes->get('/users/{slug}', $this->responder('slug'));
        $routes->post('/items/{slug}', $this->responder('post'));
        $router = $this->createRouter($routes);

        self::assertSame($static, $router->match($this->request('GET', '/items/new'))->route);
        self::assertSame($dynamic, $router->match($this->request('GET', '/items/sample?ignored=yes'))->route);
        $match = $router->match($this->request('GET', '/users/%34%32'));
        self::assertSame($numeric, $match->route);
        self::assertSame(['id' => '42'], $match->parameters);
        self::assertSame($fallback, $router->match($this->request('GET', '/users/abc'))->route);
        self::assertNull($router->match($this->request('GET', '/items/sample/'))->route);
        self::assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $router->match($this->request('DELETE', '/items/new'))->allowedMethods);
        self::assertSame([], $router->match($this->request('GET', '/unknown'))->allowedMethods);
    }

    public function testUrlEncodingRoundTripsAndInvalidSegmentsAreNotMatched(): void
    {
        $routes = new RouteCollection();
        $route = $routes->get('/items/{value}', $this->responder('ok'))->name('item');
        $router = $this->createRouter($routes);
        $urls = new UrlGenerator($routes);
        foreach (['a b', 'café', '100%', 'a+b', '%2F', 'a?b#c', 'zero0'] as $value) {
            $url = $urls->generate('item', ['value' => $value], ['q' => 'hello world']);
            $match = $router->match($this->request('GET', $url));
            self::assertSame($route, $match->route, $url);
            self::assertSame(['value' => $value], $match->parameters, $url);
        }
        foreach (['%2F', '%5C', '%00', '%0A', '%7F', '%FF', '%2e', '%2e%2e'] as $value) {
            self::assertNull($router->match($this->request('GET', '/items/' . $value))->route, $value);
        }
    }

    public function testHeadOptionsAndAllowPolicy(): void
    {
        $routes = new RouteCollection();
        $routes->get('/implicit', $this->responder('GET body'));
        $routes->post('/implicit', $this->responder('POST body'));
        $routes->get('/explicit', $this->responder('GET body'));
        $routes->head('/explicit', static fn() => (new Psr17Factory())->createResponse(202)->withHeader('X-Explicit', 'head'));
        $routes->options('/explicit', static fn() => (new Psr17Factory())->createResponse(200)->withHeader('X-Explicit', 'options'));
        $kernel = $this->kernel($this->createRouter($routes));
        $head = $kernel->handle($this->request('HEAD', '/implicit'));
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('HEAD', $head->getHeaderLine('X-Method'));
        self::assertSame('', (string) $head->getBody());
        self::assertSame(202, $kernel->handle($this->request('HEAD', '/explicit'))->getStatusCode());
        $options = $kernel->handle($this->request('OPTIONS', '/implicit'));
        self::assertSame(204, $options->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS, POST', $options->getHeaderLine('Allow'));
        self::assertSame('', (string) $options->getBody());
        self::assertSame('options', $kernel->handle($this->request('OPTIONS', '/explicit'))->getHeaderLine('X-Explicit'));
        $unsupported = $kernel->handle($this->request('DELETE', '/implicit'));
        self::assertSame(405, $unsupported->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS, POST', $unsupported->getHeaderLine('Allow'));
        self::assertSame('<h1>Method Not Allowed</h1>', (string) $unsupported->getBody());
        $json = $kernel->handle($this->request('DELETE', '/implicit')->withHeader('Accept', 'application/json'));
        self::assertSame(['message' => 'Method Not Allowed'], json_decode((string) $json->getBody(), true));
        self::assertSame(404, $kernel->handle($this->request('OPTIONS', '/missing'))->getStatusCode());
        $missing = $kernel->handle($this->request('GET', '/missing')->withHeader('Accept', 'application/json'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(['message' => 'Not Found'], json_decode((string) $missing->getBody(), true));
        $head = $kernel->handle($this->request('HEAD', '/missing'));
        self::assertSame(404, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
    }

    public function testNestedGroupsMiddlewareOrderAndControllerResolution(): void
    {
        $container = new ServiceContainer();
        $controller = new class {
            public function show(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse()->withBody((new Psr17Factory())->createStream(json_encode([
                    $request->getAttribute('id'), $request->getAttribute('_route')->getName(),
                    $request->getAttribute('_route_params'), $request->getAttribute('trace'),
                ], JSON_THROW_ON_ERROR)));
            }
        };
        $container->instance('controller', $controller);
        $middleware = static fn(string $label) => new class ($label) implements MiddlewareInterface {
            public function __construct(private readonly string $label) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $trace = [...$request->getAttribute('trace', []), $this->label];
                return $handler->handle($request->withAttribute('trace', $trace))->withAddedHeader('X-Order', $this->label);
            }
        };
        $container->instance('route.middleware', $middleware('route'));
        $routes = new RouteCollection();
        $routes->group('/api', function (RouteCollection $routes) use ($middleware): void {
            $routes->group('/v1', static function (RouteCollection $routes): void {
                $routes->get('/users/{id}', ['controller', 'show'])->where('id', '[0-9]+')->name('show')->middleware('route.middleware');
            }, [$middleware('inner')], 'v1.');
        }, [$middleware('outer')], 'api.');
        $routes->get('/outside', $this->responder('outside'))->name('outside');
        $urls = new UrlGenerator($routes);
        self::assertSame('/api/v1/users/42', $urls->generate('api.v1.show', ['id' => 42]));
        self::assertSame('/outside', $urls->generate('outside'));
        $kernel = $this->kernel($this->createRouter($routes), $container);
        $request = $this->request('GET', '/api/v1/users/42');
        for ($i = 0; $i < 2; ++$i) {
            $response = $kernel->handle($request);
            self::assertSame(['42', 'api.v1.show', ['id' => '42'], ['outer', 'inner', 'route']], json_decode((string) $response->getBody(), true));
            self::assertSame(['route', 'inner', 'outer'], $response->getHeader('X-Order'));
            self::assertNull($request->getAttribute('id'));
            self::assertNull($request->getAttribute('_route'));
        }
    }

    public function testMiddlewareShortCircuitDoesNotResolveController(): void
    {
        $container = new ServiceContainer();
        $container->factory('must.not.resolve', static function (): never {
            throw new RuntimeException('Resolved controller too early.');
        });
        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects(self::once())->method('process')->willReturn((new Psr17Factory())->createResponse(403));
        $routes = new RouteCollection();
        $routes->get('/private', ['must.not.resolve', 'show'])->middleware($middleware);
        self::assertSame(403, $this->kernel($this->createRouter($routes), $container)->handle($this->request('GET', '/private'))->getStatusCode());
    }

    public function testPsr15HandlerServices(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(201)->withHeader('X-Id', $request->getAttribute('id'));
            }
        };
        $container = new ServiceContainer();
        $container->instance('handler', $handler);
        $routes = new RouteCollection();
        $routes->get('/service/{id}', 'handler');
        $routes->get('/instance/{id}', $handler);
        $kernel = $this->kernel($this->createRouter($routes), $container);
        foreach (['/service/8', '/instance/8'] as $path) {
            $response = $kernel->handle($this->request('GET', $path));
            self::assertSame(201, $response->getStatusCode());
            self::assertSame('8', $response->getHeaderLine('X-Id'));
        }
    }
}
