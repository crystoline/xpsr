# Portable routing

XPSR owns route definitions, handler execution, middleware, and URL generation. Matching is supplied by an adapter implementing `Crystoline\Contracts\Routing\RouterInterface`. There is no PSR router registration interface: runtime requests and responses use PSR-7, handlers and middleware use PSR-15, factories use PSR-17, and service resolution uses PSR-11.

## Choose an adapter

Run `php xpsr setup --router=fastroute` (the default) or `php xpsr setup --router=sunrise --install-dependencies`. Setup writes `ROUTER_ADAPTER` to `.env`, installs the adapter's package if needed, and checks that the router matches your routes; see `CONSOLE.md`. You can also set `ROUTER_ADAPTER` yourself. The selection and route files live in `config/routing.php`. Both adapters consume exactly the same definitions.

Each adapter is its own Composer package, so an application installs only the router it uses:

| `ROUTER_ADAPTER` | Package | Class | Router library |
|---|---|---|---|
| `fastroute` | `crystoline/xpsr-router-fastroute` | `Crystoline\Router\FastRoute\FastRouteRouter` | FastRoute 1.3 |
| `sunrise` | `crystoline/xpsr-router-sunrise` | `Crystoline\Router\Sunrise\SunriseRouter` | Sunrise 2.16 (needs `psr/http-message` 1.x) |

The skeleton requires the FastRoute adapter. The Sunrise adapter is a suggestion and, in this repository, a development requirement so both adapters are tested. A `composer install --no-dev` deployment includes Sunrise only after `composer require crystoline/xpsr-router-sunrise`, which setup runs for you. The framework knows these two names without depending on either package. If the selected adapter is not installed, resolving the router fails with the setup command to run.

A custom adapter can be configured by its service class. It must implement `RouterInterface`; register a container factory if it needs dependencies beyond autowiring. The built-in adapters use the shared collection. Replace the `RouterInterface` binding directly for a different registration arrangement.

Configure adapters before resolving the HTTP kernel or router. These services are shared; changing configuration afterward does not rebuild existing consumers.

## Declare routes

Each configured route file returns a callable accepting the shared collection. Paths are relative to the application root, so loading does not depend on the working directory.

```php
use App\Http\Controller\UserController;
use App\Http\Middleware\Authenticate;
use Crystoline\Routing\RouteCollection;

return static function (RouteCollection $routes): void {
    $routes->get('/users/{id}', [UserController::class, 'show'])
        ->where('id', '\d+')
        ->name('users.show');

    $routes->group('/admin', static function (RouteCollection $routes): void {
        $routes->post('/users', [UserController::class, 'create'])->name('users.create');
    }, middleware: [Authenticate::class], namePrefix: 'admin.');
};
```

Helpers exist for GET, POST, PUT, PATCH, DELETE, HEAD, and OPTIONS. Use `add(['GET', 'POST'], '/path', $handler)` for multiple methods or another method made of uppercase letters. Empty method lists, repeated method/pattern registrations, wildcard methods, and duplicate names fail explicitly.

Groups expand their static prefixes, name prefixes, and middleware at registration time. Nested groups inherit their outer group's settings. A group's root route declared as `'/'` retains its trailing slash: prefix `'/admin'` plus `'/'` produces `'/admin/'`. Declare `'/admin'` outside the group when that exact URL is desired.

The collection freezes when loaded by the provider or first consumed by an adapter/URL generator. Subsequent additions or fluent changes throw. This prevents URL generation and matching from using different versions of a definition.

## Portable path grammar

- Paths start with one slash. Static segments use ASCII letters, digits, dots, underscores, hyphens, and tildes.
- A placeholder occupies a whole segment, such as `/users/{id}`. Names start with a letter or underscore, contain only letters/digits/underscores, and have at most 32 characters. Repeated names and the reserved names `_route` and `_route_params` are rejected.
- Constraints use `where()`, not inline vendor syntax. Initially supported: `\d+` and positive ASCII character classes followed by `+`, such as `[0-9]+`, `[a-z]+`, or `[A-Za-z0-9_-]+`. Ranges must match only letters, digits, underscores, or hyphens.
- Optional segments, partial-segment placeholders, catch-all slashes, host patterns, arbitrary regexes, and wildcard methods are outside this initial grammar and fail at registration.
- Matching is case-sensitive and trailing slashes are significant. Query strings do not affect selection.
- For a requested method, static routes win over variable routes regardless of declaration order. Overlapping variable routes use declaration order; put constrained routes before broader routes. Identical patterns for the same method are rejected even if placeholder names differ. Semantically overlapping but textually different constraints remain ordered.
- Paths are normalized segment by segment. Parameters decode once; generated values encode once. UTF-8, spaces, plus signs, and literal percent signs work in unconstrained parameters. Encoded slash/backslash, controls, invalid UTF-8, and dot segments are rejected as unmatched paths. A literal value `%2F` generates `%252F` and is returned as `%2F`, without a second decoding pass. Do not decode route attributes again.

Use `defaults(['id' => 42])` for URL-generation defaults. Defaults must refer to placeholders and satisfy their constraints; they do not make a required path segment optional.

## Handlers and middleware

Supported handlers are PSR-15 handler instances, service IDs resolving to a PSR-15 handler or invokable object, callables, and controller pairs such as `[UserController::class, 'show']`. String entries are treated as service IDs. Controller constructors use ordinary container injection.

Callables and controller methods receive the current server request as their single argument and must return a PSR-7 response. There is no action-parameter autowiring, automatic scalar conversion, or implicit JSON conversion.

```php
public function show(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
{
    $id = $request->getAttribute('id'); // Decoded string.
    // Use your injected services to build a response.
}
```

The request receives each matched parameter by name, `_route` containing its `RouteDefinition`, and `_route_params` containing the parameter map. Routing passes an immutable request copy onward; it does not modify the original request.

Global middleware runs first. After a successful match, group middleware runs from outer to inner, followed by route middleware in declaration order, then the handler. Responses return through that order in reverse. Middleware can short-circuit; the controller is resolved only when execution reaches it. The kernel's exception boundary covers global middleware, matching, route middleware, and handlers.

Middleware entries must be PSR-15 instances or service IDs resolving to them. Explicit singleton services retain their configured lifetime; the routing layer does not automatically reset application service state.

## HTTP outcomes

| Request | Behavior |
| --- | --- |
| No matching path | 404 |
| Existing path, unsupported method | 405 with sorted, unique `Allow` methods |
| HEAD with an explicit HEAD route | Run that route |
| HEAD without one, but with GET | Run GET's handler with the original HEAD request |
| Any HEAD response | Kernel removes the body after middleware/error handling, retaining status and headers |
| OPTIONS with an explicit route | Run that route and its middleware |
| OPTIONS on an existing path without an explicit route | 204 with `Allow` |
| OPTIONS on a missing path | 404 |

`Allow` combines matching methods across overlapping definitions, adds HEAD when GET exists, and adds OPTIONS. Automatic OPTIONS does not run route middleware and does not provide CORS authorization headers. Put cross-cutting policy in global middleware or declare an explicit OPTIONS route. The kernel does not infer Content-Length for HEAD.

404 and 405 are thrown by `RouteDispatcher` as `Crystoline\Http\Exception\NotFoundHttpException` and `MethodNotAllowedHttpException` (the latter carries `Allow`). The kernel's exception handler renders them with the same JSON or HTML body as other errors; see `HTTP.md`. Global middleware therefore sees these outcomes as exceptions, not as 404/405 responses. A middleware that needs a fallback for unmatched paths should catch `NotFoundHttpException`.

Direct use of `RouteDispatcher` bypasses the outer kernel, including HEAD body suppression and error rendering, so 404/405 surface as exceptions. Applications should use `Application::getHttpKernel()`.

## Named URLs

Inject `Crystoline\Contracts\Routing\UrlGeneratorInterface` or resolve it from the application:

```php
$urls = $application->get(\Crystoline\Contracts\Routing\UrlGeneratorInterface::class);
$path = $urls->generate('users.show', ['id' => 42], ['tab' => 'profile details']);
// /users/42?tab=profile%20details
```

Parameters accept strings and integers. Unknown route names, missing/extra path parameters, unsupported values, and constraint violations throw `InvalidArgumentException`. Query parameters are supplied separately and encoded with PHP's RFC 3986 query builder. Generated URLs are root-relative paths; absolute URLs, host/scheme selection, and signed URLs are not part of this milestone.

## Adapter contract and migration

`RouterInterface::match(ServerRequestInterface): RouteMatch` returns a matched definition and decoded parameters, or a missing result. A missing result with an empty allowed-method list means 404; a nonempty list means method-not-allowed (or automatic OPTIONS when requested). Matching never invokes controllers or middleware.

Custom adapters must implement the documented ordering, normalization, HEAD fallback, and allowed-method policy. `AbstractRouter` supplies that policy for the built-in adapters; its native matcher must perform exact-method matching with encoded parameters. To prove an adapter, extend `Crystoline\Testing\RouterContractTestCase` in its tests and implement `createRouter()`. Both official adapters run exactly this suite (it needs `phpunit/phpunit` and `nyholm/psr7` as development dependencies).

The previous `RouterInterface::dispatch()`, Sunrise-bound route files, framework `Routing\Router` wrapper, and `getUnderlyingRouter()` are replaced by this API. Move native registrations to callable collection files; update custom kernel construction to pass a PSR-15 terminal handler, exception handler, PSR-17 stream factory, and optional middleware. Native Sunrise route handlers/middleware are no longer executed by the adapter.

The same adapter contract tests cover Sunrise and FastRoute. Application integration tests switch adapters using the same example files. `php xpsr route:list` inspects these definitions; see `CONSOLE.md` for filters and JSON output. Route caches, advanced route grammar, and comprehensive emitter/session integration remain later work.

Upstream references: [FastRoute](https://github.com/nikic/FastRoute/tree/v1.3.0), [Sunrise router](https://github.com/sunrise-php/http-router), [PHP-FIG accepted standards](https://www.php-fig.org/psr/).
