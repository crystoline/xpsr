# crystoline/xpsr-http-client

Outgoing HTTP for XPSR applications: a configured [PSR-18](https://www.php-fig.org/psr/psr-18/) client (`Psr\Http\Client\ClientInterface`), named clients with their own base URL and headers, and a fake client for tests. It is backed by [Symfony HttpClient](https://symfony.com/doc/current/http_client.html), which uses cURL when the extension is loaded and PHP streams otherwise.

```sh
composer require crystoline/xpsr-http-client
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\HttpClient\HttpClientServiceRegistry::class,
```

Then inject the client. Requests and responses are PSR-7 messages from the application's PSR-17 factories:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

public function __construct(
    private readonly ClientInterface $http,
    private readonly RequestFactoryInterface $requests,
) {}

$response = $this->http->sendRequest($this->requests->createRequest('GET', 'https://api.example.com/status'));
if ($response->getStatusCode() !== 200) {
    // 4xx and 5xx responses are returned, not thrown.
}
$status = json_decode((string) $response->getBody(), true);
```

## Configuration

Configuration is optional. Create `config/http_client.php` to change the defaults:

```php
return [
    'timeout' => 10,          // seconds without activity before a request fails
    'max_duration' => 0,      // seconds a whole request may take; 0 for no limit
    'max_redirects' => 5,     // 0 returns 3xx responses instead of following them
    'headers' => ['User-Agent' => 'my-app/1.0'],

    // Named clients, each a service: http_client.<name>
    'clients' => [
        'github' => [
            'base_uri' => 'https://api.github.com',
            'headers' => ['Accept' => 'application/vnd.github+json', 'Authorization' => 'Bearer ' . env('GITHUB_TOKEN')],
            'timeout' => 5,
        ],
    ],
];
```

Unknown options and malformed values fail when a client is first used, with the name of the setting, for example `http_client.clients.github.timeout must be a number of seconds greater than 0.`

A named client starts from the default options. Its headers are added to the default headers and replace a default header of the same name, ignoring case. Its `base_uri` resolves relative request URLs, so `createRequest('GET', 'repos/php-fig/http-client')` on the `github` client requests `https://api.github.com/repos/php-fig/http-client`. Bind it to the class that uses it in `config/factory.php`:

```php
App\Services\GitHub::class => static fn (Psr\Container\ContainerInterface $c) => new App\Services\GitHub(
    $c->get('http_client.github'),
    $c->get(Psr\Http\Message\RequestFactoryInterface::class),
),
```

Symfony HttpClient takes proxies from the `http_proxy`, `https_proxy`, and `no_proxy` environment variables (uppercase `HTTP_PROXY` only on the command line, because web servers let clients set it). TLS certificates are always verified.

## Errors

PSR-18 defines what is thrown:

| Situation | Result |
|---|---|
| Any HTTP response, including 4xx and 5xx | Returned as a response |
| Connection refused, DNS failure, TLS failure, `timeout` or `max_duration` exceeded | `Psr\Http\Client\NetworkExceptionInterface` |
| A request that cannot be sent, such as a URL without a host | `Psr\Http\Client\RequestExceptionInterface` |

Both exceptions implement `Psr\Http\Client\ClientExceptionInterface` and return the request from `getRequest()`.

## Symfony's API

`Symfony\Contracts\HttpClient\HttpClientInterface` is registered too, with the default options. Inject it for what PSR-18 cannot express: streaming large downloads, or sending several requests at once:

```php
$responses = [];
foreach ($urls as $url) {
    $responses[] = $this->client->request('GET', $url); // starts all requests
}
foreach ($responses as $response) {
    $data[] = $response->toArray(); // waits for each
}
```

## Testing

`Crystoline\HttpClient\Testing\FakeClient` answers requests from a queue and records them, so tests never reach the network. Replace the client with `swap()` from the skeleton's `tests/TestCase.php`:

```php
use Crystoline\HttpClient\Testing\FakeClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

$factory = new Psr17Factory();
$fake = new FakeClient(
    $factory->createResponse(200)->withBody($factory->createStream('{"status":"ok"}')),
    static fn (RequestInterface $request) => $factory->createResponse(503),
);
$this->swap(ClientInterface::class, $fake);           // or 'http_client.github'

$response = $this->get('/status');

self::assertSame('https://api.example.com/status', (string) $fake->lastRequest()->getUri());
self::assertSame(1, $fake->remaining());
```

A queued closure receives the request and returns a response, or throws to simulate a failure. A request with nothing left in the queue fails with a `LogicException` naming the request, so an unexpected call does not pass silently.

## Services

| Service ID | Provides |
|---|---|
| `Psr\Http\Client\ClientInterface` | The default PSR-18 client. Shared. |
| `Symfony\Contracts\HttpClient\HttpClientInterface` | The same client with Symfony's API. Shared. |
| `http_client.<name>` | A PSR-18 client for each entry in `clients`. Shared, and sharing the default client's connections. |

Requires PHP 8.4+.
