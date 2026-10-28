# Sessions, cookies, and CSRF

A session keeps values for one browser between requests, such as who is signed in or a message to show after a redirect. XPSR's sessions are PSR-15 middleware over a small store interface. They do not use PHP's `session_start()`: nothing is sent or stored for a request until its session holds something, and a session's values never contain serialized PHP objects.

Pages for browsers live in `routes/web.php`, where new projects already have a group with `SessionMiddleware` and `CsrfMiddleware`:

```php
$routes->group('', static function (RouteCollection $routes): void {
    $routes->get('/', [HomeController::class, 'index'])->name('home');
    // Add your pages and forms here.
}, middleware: [SessionMiddleware::class, CsrfMiddleware::class]);
```

API routes for clients that send a token with each request stay in `routes/api.php`, without sessions or CSRF checks.

## Reading and writing

```php
use Crystoline\Xpsr\Session\Session;

public function store(ServerRequestInterface $request): ResponseInterface
{
    $session = Session::of($request);

    $session->set('cart', [12, 40]);          // kept until removed or the session ends
    $cart = $session->get('cart', []);        // a default when it is missing
    $session->has('cart');
    $session->remove('cart');

    $session->flash('status', 'Task saved.'); // readable in this request and the next one only

    return $this->responses->redirect('/tasks');
}
```

`Session::of()` throws a `LogicException` naming the middleware when the route has no session. Values must be JSON data: `null`, booleans, numbers, strings, and arrays of these. Store an ID rather than an object, for example `$session->set('user_id', $user->id)`; anything else is refused with an `InvalidArgumentException` when you set it.

`redirect()` on `Crystoline\Xpsr\Http\Response\ResponseBuilder` answers 303 by default, so the browser follows a form post with a GET.

## Forms and CSRF

`CsrfMiddleware` rejects requests that change state (every method except GET, HEAD, OPTIONS, and TRACE) unless they carry the session's CSRF token. Put it in forms with `csrf_field()`:

```twig
<form method="post" action="/tasks">
    {{ csrf_field() }}
    <input name="title">
    <button>Save</button>
</form>
```

which writes `<input type="hidden" name="_token" value="...">`. Scripts send it in the `X-CSRF-Token` header; put it in the page for them:

```twig
<meta name="csrf-token" content="{{ csrf_token() }}">
```

```js
fetch('/tasks', {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content},
    body: JSON.stringify({title: 'Ship it'}),
});
```

A JSON body can also carry it as `"_token"`. A request without the right token is answered with 403 and "This form has expired. Reload the page and try again." in every environment, and the handler does not run. Showing a form starts a session, because the token lives in it.

Every unsafe request to a route with `CsrfMiddleware` needs the token, including a browser's first form post such as a login form; there is no exception for browsers without a session. Keep webhooks and token-authenticated APIs outside the group.

The session cookie is also `SameSite=Lax`, so browsers do not send it with forms posted from other sites. The token is the second line of defense, for older browsers and for requests from your own subdomains.

## Signing in and out

```php
// After checking the password:
$session->regenerate();               // new ID, same values: an ID planted before the login is useless
$session->set('user_id', $user->id);

// On logout:
$session->invalidate();               // values and CSRF token removed; the browser's cookie is deleted
```

A browser's session ID is accepted only when the store has a session under it. An unknown or malformed ID gets a new random one (160 bits), so nobody can choose another browser's session ID.

## Views

| Function | Writes |
|---|---|
| `{{ csrf_field() }}` | The hidden `_token` input. |
| `{{ csrf_token() }}` | The token, for a meta tag or a script. |
| `{{ session('status') }}` | A session value, escaped like any other; `session('status', 'default')` for a default. |

```twig
{% if session('status') %}<p class="notice">{{ session('status') }}</p>{% endif %}
```

`csrf_token()` and `csrf_field()` need a session: render the view from a route behind `SessionMiddleware`. `session()` returns the default outside one.

## Configuration

`config/session.php`:

| Option | Default | Meaning |
|---|---|---|
| `store` | `SESSION_STORE`, or `file` | Where sessions are kept: `file`, `cache`, `database`, `array`, or a service; see [Stores](#stores). |
| `lifetime` | `SESSION_LIFETIME`, or `2 hours` | How long a session lasts without requests, as a period such as `30 minutes` or `14 days`. Each request starts it again. |
| `prune_every` | `100` | About one request in this many removes expired sessions from the file and database stores. `0` never does; run `php xpsr session:prune` from cron instead. |
| `cookie.name` | `xpsr_session` | The cookie's name. |
| `cookie.path`, `cookie.domain` | `/`, none | Where browsers send the cookie. Without a domain, only to this host. |
| `cookie.secure` | `null` | `null`: Secure when the request came over HTTPS. `true` or `false` to decide yourself. |
| `cookie.same_site` | `Lax` | `Lax`, `Strict` (not sent when arriving from a link on another site either), or `None` (needs `secure` true). |
| `file.path` | `var/sessions` | The file store's directory, relative to the project root. |
| `database.table`, `database.connection` | `sessions`, the default | The database store's table and connection. |

The cookie is always `HttpOnly`: scripts cannot read the session ID. Behind a proxy or load balancer that terminates HTTPS, list it in `TRUSTED_PROXIES`, otherwise requests look like plain HTTP and the cookie is not marked Secure.

## Stores

| Store | Notes |
|---|---|
| `file` | One file per session in `var/sessions`, readable only by the owner. The web server must be able to write there. One server only. |
| `cache` | The application cache from `crystoline/xpsr-cache`, with the session lifetime as time to live. Needs a cache that outlives requests and that all servers share for several servers, such as Redis through the `service` driver. `cache:clear` signs everybody out. |
| `database` | A table on a database connection from `crystoline/xpsr-database`. Create it with `php xpsr make:session-table`, then `php xpsr migrate`. Works for several servers. |
| `array` | In memory for one PHP process. The skeleton's `phpunit.xml` sets `SESSION_STORE=array`, so tests leave no files. Never on a web server. |

Requests for the same session are not locked against each other: when two run at once, the one that finishes last writes its values. Keep values that several tabs change at once, such as counters, in the database instead.

A store is a class implementing `Crystoline\Xpsr\Session\SessionStoreInterface`: `read()`, `write()`, `destroy()`, and `prune()` on opaque payload strings. Bind it in `config/factory.php` and name its service ID in `store`. Check it with `Crystoline\Xpsr\Testing\SessionStoreContractTestCase`, which the built-in stores pass:

```php
final class RedisSessionStoreTest extends SessionStoreContractTestCase
{
    protected function createStore(int $lifetime, MockClock $clock): SessionStoreInterface
    {
        return new RedisSessionStore($this->redis, $lifetime, $clock);
    }
}
```

## Other cookies

`Crystoline\Xpsr\Http\Cookie` writes a `Set-Cookie` header with safe defaults (HttpOnly, Secure, SameSite=Lax); read cookies with `$request->getCookieParams()`:

```php
use Crystoline\Xpsr\Http\Cookie;

$response = (new Cookie('theme', 'dark', maxAge: 31_536_000))->addTo($response);
$response = Cookie::forget('theme')->addTo($response);
```

Browsers accept Secure cookies on `http://localhost`. For another plain-HTTP host, pass `secure: false`.

## Testing

Within one test, the application's `TestCase` keeps the cookies that responses set and sends them with later requests, like one browser:

```php
public function testATaskCanBeCreated(): void
{
    $this->withSession(['user_id' => 7]);                    // act as a signed-in user

    $response = $this->postForm('/tasks', ['title' => 'Ship it']);   // sends the CSRF token for you
    self::assertSame(303, $response->getStatusCode());
    self::assertSame('Task saved.', $this->session()->get('status'));
}
```

| Helper | Does |
|---|---|
| `postForm($uri, $data)` | Posts a form, adding the session's `_token` unless `$data` has one. |
| `csrfToken()` | The session's token, for `X-CSRF-Token` in `request()`. |
| `withSession($values)` | Stores values in the test browser's session. |
| `session()` | The session as stored now. |
| `send($request)` | Sends a PSR-7 request you built, with the test's cookies. |

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Session\SessionMiddleware`, `CsrfMiddleware` | The middleware for route groups. |
| `Crystoline\Xpsr\Session\SessionManager` | Loads and saves sessions; used by the middleware and the test helpers. |
| `Crystoline\Xpsr\Session\SessionStoreInterface` | The configured store. |
| `Crystoline\Xpsr\Session\CurrentSession` | The session of the request being handled, for code without the request. Prefer `Session::of($request)`. |

`Crystoline\Xpsr\Registry\SessionServiceRegistry` registers them and `session:prune`; new projects list it in `app.registry`.
