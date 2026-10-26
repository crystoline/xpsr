# XPSR HTTP pipeline

A request travels through `public/index.php`, the HTTP kernel, global middleware, the router, route middleware, and the handler. The response goes back the same way and is then sent by the response emitter.

```text
public/index.php ─ creates the request from PHP globals (Nyholm PSR-7)
  └ HttpKernel ─ error boundary: any exception becomes a rendered error response
      └ global middleware (app.middleware), first listed runs first
          └ RouteDispatcher ─ 404 / 405 / automatic OPTIONS, then route middleware and the handler
  └ HEAD requests: body removed after middleware and error rendering
ResponseEmitter ─ headers, status line, body
```

## Request bodies

PHP parses `application/x-www-form-urlencoded` and `multipart/form-data` only for POST. The skeleton enables `Crystoline\Http\Middleware\BodyParsingMiddleware` in `config/app.php` to cover the rest:

| Content type | Methods | `getParsedBody()` |
|---|---|---|
| `application/json`, `application/*+json` | all | Decoded to arrays. Integers too large for PHP become strings. |
| `application/x-www-form-urlencoded` | POST | `$_POST`, parsed by PHP |
| `application/x-www-form-urlencoded` | PUT, PATCH, DELETE, … | Parsed by the middleware, with the same array syntax (`tags[]=a`) |
| `multipart/form-data` | POST | `$_POST` and uploaded files, parsed by PHP |
| `multipart/form-data` | other methods | Not parsed |
| anything else | all | Unchanged |

Malformed JSON, or JSON that is a string, number, or `null` rather than an object or array, produces a **400** response. A body larger than the limit (1 MiB by default) produces a **413** response. An empty body leaves the parsed body unset. The raw body remains readable afterwards: `(string) $request->getBody()`.

Because this is global middleware, it runs before routing. A malformed JSON body sent with an unsupported method gets 400, not 405. To change the limit, bind the middleware in `config/factory.php`:

```php
Crystoline\Http\Middleware\BodyParsingMiddleware::class => static fn() => new Crystoline\Http\Middleware\BodyParsingMiddleware(5 * 1024 * 1024),
```

Remove it from `app.middleware` to handle bodies yourself.

## Errors

The kernel catches every exception from middleware, routing, and handlers and passes it to `Crystoline\Exception\ExceptionHandlerInterface`. The default `ExceptionHandler`:

- uses the exception's status and headers (such as `Allow`) for HTTP exceptions, and 500 for everything else;
- returns JSON (`{"message": ...}`) when the `Accept` header prefers `application/json` or a `+json` type, and HTML otherwise;
- shows only the standard reason phrase, such as "Internal Server Error", unless `APP_DEBUG=true`. With debug enabled, JSON includes the exception message. HTML shows the Whoops error page if `filp/whoops` is installed (the skeleton installs it as a development dependency); otherwise it shows a plain built-in page with each exception in the chain, its location, and its stack trace. Debug is off unless explicitly enabled;
- logs 5xx errors, but not 4xx errors, through PSR-3.

### HTTP exceptions

Throw these from handlers and middleware to send an error status. They are in `Crystoline\Http\Exception`:

| Class | Status | Extra constructor argument |
|---|---|---|
| `BadRequestHttpException` | 400 | |
| `UnauthorizedHttpException` | 401 | `$challenge` → `WWW-Authenticate`, for example `'Bearer realm="api"'` |
| `ForbiddenHttpException` | 403 | |
| `NotFoundHttpException` | 404 | |
| `MethodNotAllowedHttpException` | 405 | `$allowedMethods` → `Allow` |
| `ConflictHttpException` | 409 | |
| `PayloadTooLargeHttpException` | 413 | |
| `UnsupportedMediaTypeHttpException` | 415 | |
| `UnprocessableEntityHttpException` | 422 | |
| `TooManyRequestsHttpException` | 429 | `$retryAfterSeconds` → `Retry-After` |
| `ServiceUnavailableHttpException` | 503 | |
| `HttpException` | any 400–599 | `$statusCode` first |

The other arguments are `$message`, `$previous`, `$headers`, and `$code`. The message reaches clients only when `APP_DEBUG=true`. Statuses 500 and above are logged; client errors are not. To create your own, implement `Crystoline\Http\Exception\HttpExceptionInterface` or extend `HttpException`.

Unmatched routes (404) and unsupported methods (405) are thrown as `NotFoundHttpException` and `MethodNotAllowedHttpException`, so they receive the same JSON or HTML body as other errors.

Symfony's HttpKernel exceptions (`Symfony\Component\HttpKernel\Exception\*`) are also rendered with their status and headers when `symfony/http-kernel` is installed. The framework no longer depends on that package; it was required only for these classes.

If the application cannot even boot, `public/index.php` logs the cause with `error_log()` and returns a plain-text "Internal Server Error" with status 500.

## Proxies

`Crystoline\Factory\ServerRequestFactory` builds the request from PHP's globals. It keeps `Forwarded` and `X-Forwarded-*` headers, which set the scheme (for example HTTPS behind a TLS-terminating load balancer), only when the client address matches `app.trusted_proxies`. Configure that list with `TRUSTED_PROXIES`, comma-separated IP addresses or CIDR ranges. From any other address, those headers are removed from the request and its server parameters. See [DEPLOYMENT.md](DEPLOYMENT.md#proxies-and-https).

## Logging

The default `Psr\Log\LoggerInterface` is `Crystoline\Log\ErrorLogLogger`. It writes through PHP's `error_log()`, so messages go to the web server's error log (Apache, PHP-FPM), to stderr for the CLI and development server, or to the file named by the `error_log` ini setting. Logged exceptions include their stack trace. `LOG_LEVEL` sets the minimum level (`debug` by default; for example `LOG_LEVEL=warning`). Replace the binding in `config/factory.php` to use another PSR-3 logger, such as Monolog.

## Sending the response

`Crystoline\Http\ResponseEmitter` sends:

1. Every header value in order. Repeated values become separate header lines, and `Set-Cookie` never replaces earlier cookies, including ones set by PHP's session functions.
2. The status line, last, with the response's status passed on every header call. This prevents PHP from changing the status, for example turning a `Location` header into a 302.
3. The body, in 8 KiB chunks. Seekable bodies are rewound first. A valid `Content-Length` caps the bytes sent. Non-seekable (streamed) bodies are flushed after each chunk so clients receive them as they are produced. Emission stops if the client disconnects. Statuses 1xx, 204, 205, and 304 never send a body.

If output has already started, for example from an `echo` or a PHP warning, headers can no longer be sent. The emitter then throws instead of sending a partial response silently. `public/index.php` logs `Cannot send the response: output already started at file:line`. Return output in the response body instead of echoing it.

The emitter does not add `Content-Length`, compress, or handle range requests; the web server or middleware can do that.

## Verification

`tests/Integration/FrontControllerTest.php` serves a skeleton copy with PHP's built-in server and checks raw HTTP responses:

- status lines with custom reasons;
- repeated headers and cookies;
- 301 with `Location`, 204, streamed bodies, HEAD;
- JSON 404s and body parsing;
- production and debug errors with logging;
- bootstrap failures and output that starts too early.

Unit tests cover the emitter, body parser, logger, and routing errors in detail.
