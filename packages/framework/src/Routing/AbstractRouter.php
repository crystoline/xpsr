<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Routing;

use Crystoline\Xpsr\Contracts\Routing\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Shared HTTP policy; nativeMatch must match only the supplied method, without fallback. */
abstract class AbstractRouter implements RouterInterface
{
    /** @var list<string> */
    private array $methods;

    public function __construct(RouteCollection $routes)
    {
        $methods = [];
        foreach ($routes->all() as $route) {
            array_push($methods, ...$route->methods);
        }
        $this->methods = array_values(array_unique($methods));
    }

    final public function match(ServerRequestInterface $request): RouteMatch
    {
        $path = RoutePattern::normalizePath($request->getUri()->getPath());
        if ($path === null) {
            return RouteMatch::missing();
        }
        $request = $request->withUri($request->getUri()->withPath($path));
        $match = $this->nativeMatch($request);
        if ($match === null && $request->getMethod() === 'HEAD') {
            $match = $this->nativeMatch($request->withMethod('GET'));
        }
        if ($match !== null) {
            return RouteMatch::found($match->route, array_map(rawurldecode(...), $match->parameters));
        }
        $allowed = [];
        foreach ($this->methods as $method) {
            if ($this->nativeMatch($request->withMethod($method)) !== null) {
                $allowed[] = $method;
            }
        }
        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
            $allowed = array_values(array_unique($allowed));
            sort($allowed);
        }
        return RouteMatch::missing($allowed);
    }

    abstract protected function nativeMatch(ServerRequestInterface $request): ?RouteMatch;
}
