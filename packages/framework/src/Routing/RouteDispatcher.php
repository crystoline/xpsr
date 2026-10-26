<?php

declare(strict_types=1);

namespace Crystoline\Routing;

use Crystoline\Contracts\Routing\RouterInterface;
use Crystoline\Http\MiddlewarePipeline;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use UnexpectedValueException;

final class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ContainerInterface $container,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->router->match($request);
        if ($match->route === null) {
            // 404 and 405 are rendered by the exception handler, so they get the same negotiated body as other errors.
            if ($match->allowedMethods === []) {
                throw new NotFoundHttpException('No route matches ' . $request->getUri()->getPath() . '.');
            }
            if ($request->getMethod() !== 'OPTIONS') {
                throw new MethodNotAllowedHttpException($match->allowedMethods, $request->getMethod() . ' is not allowed for this route.');
            }
            return $this->responses->createResponse(204)->withHeader('Allow', implode(', ', $match->allowedMethods));
        }
        foreach ($match->parameters as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        $request = $request->withAttribute('_route', $match->route)->withAttribute('_route_params', $match->parameters);
        $handler = new class ($match->route, new HandlerResolver($this->container)) implements RequestHandlerInterface {
            public function __construct(private readonly RouteDefinition $route, private readonly HandlerResolver $resolver) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->resolver->handle($this->route->handler, $request);
            }
        };
        $middleware = [];
        foreach ($match->route->getMiddleware() as $entry) {
            $item = is_string($entry) ? $this->container->get($entry) : $entry;
            if (!$item instanceof MiddlewareInterface) {
                throw new UnexpectedValueException('Route middleware must implement PSR-15.');
            }
            $middleware[] = $item;
        }
        return (new MiddlewarePipeline($handler, $middleware))->handle($request);
    }
}
