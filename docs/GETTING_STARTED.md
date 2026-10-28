# Getting started with XPSR

This tutorial builds a small tasks API: three endpoints, an API-key middleware, a console command, and tests. Then it switches the router adapter without touching any route. Every step was run in a freshly created project; the commands and files below are the ones that were tested.

You need PHP 8.4 or newer and Composer 2. On Linux and macOS, `php xpsr serve` also needs the `pcntl` extension.

## 1. Create a project

```sh
composer create-project crystoline/xpsr my-app
cd my-app
```

To try unreleased changes, create a project from a clone of the XPSR repository instead:

```sh
php scripts/export-skeleton.php /tmp/xpsr-skeleton
composer create-project crystoline/xpsr my-app 0.3.x-dev --add-repository \
  --repository='{"type":"path","url":"/tmp/xpsr-skeleton","options":{"symlink":false,"versions":{"crystoline/xpsr":"0.3.x-dev"}}}' \
  --repository='{"type":"path","url":"'"$PWD"'/packages/*","options":{"symlink":false,"versions":{"crystoline/xpsr-framework":"0.3.x-dev","crystoline/xpsr-router-fastroute":"0.3.x-dev","crystoline/xpsr-router-sunrise":"0.3.x-dev"}}}'
cd my-app
```

Composer creates `.env` from `.env.example`. Now choose the router and preset. This tutorial builds an API, so it uses the `api` preset, which loads only `routes/api.php`:

```sh
php xpsr setup --router=fastroute --preset=api
```

```text
Router: fastroute. Preset: api.
Write config/routing.php to load routes/api.php.
Bootstrap check passed: 1 route(s); 1 without placeholders matched by Crystoline\Xpsr\Router\FastRoute\FastRouteRouter.
```

Start the development server and keep it running in another terminal:

```sh
php xpsr serve          # http://127.0.0.1:3001
curl http://127.0.0.1:3001/hello
```

## 2. Add a controller and routes

```sh
php xpsr make:controller TaskController
```

Replace the generated `app/Http/Controller/TaskController.php`. There is no database integration yet, so the tasks are a constant:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Crystoline\Xpsr\Http\Exception\BadRequestHttpException;
use Crystoline\Xpsr\Http\Exception\NotFoundHttpException;

final class TaskController
{
    private const TASKS = [
        1 => ['id' => 1, 'title' => 'Read the XPSR docs'],
        2 => ['id' => 2, 'title' => 'Build an endpoint'],
    ];

    public function __construct(private readonly ResponseBuilder $responses) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->json(array_values(self::TASKS));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');

        return $this->responses->json(self::TASKS[$id] ?? throw new NotFoundHttpException("Task {$id} does not exist."));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $data = $request->getParsedBody();
        $title = is_array($data) && is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title === '') {
            throw new BadRequestHttpException('A task needs a title.');
        }

        return $this->responses->json(['id' => 3, 'title' => $title], 201);
    }
}
```

Things to notice:

- **Injection:** the constructor's `ResponseBuilder` is supplied by the container. Controllers need no registration.
- **Route parameters:** the `{id}` placeholder arrives as a request attribute.
- **Request bodies:** JSON is already decoded in `getParsedBody()`, by the body-parsing middleware the skeleton enables.
- **Errors:** throwing one of XPSR's HTTP exceptions (`Crystoline\Xpsr\Http\Exception\*`) produces that status code. API clients get `{"message": "Bad Request"}`; the detailed message appears only with `APP_DEBUG=true`.

Add the routes to `routes/api.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Controller\TaskController;
use App\Http\Middleware\RequireApiKey;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\Routing\RouteCollection;
use Psr\Http\Message\ResponseInterface;

return static function (RouteCollection $routes): void {
    $routes->get('/hello', static function (): ResponseInterface {
        return app()->get(ResponseBuilder::class)->json(['helloooooooo']);
    })->name('hello');

    $routes->get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    $routes->get('/tasks/{id}', [TaskController::class, 'show'])->where('id', '\d+')->name('tasks.show');
    $routes->post('/tasks', [TaskController::class, 'store'])->name('tasks.store')->middleware(RequireApiKey::class);
};
```

`where('id', '\d+')` makes `/tasks/abc` a 404 instead of reaching the controller. The POST route uses a middleware you create next.

## 3. Protect a route with middleware

```sh
php xpsr make:middleware RequireApiKey
```

Replace `app/Http/Middleware/RequireApiKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequireApiKey implements MiddlewareInterface
{
    public function __construct(
        private readonly Configuration $config,
        private readonly ResponseBuilder $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = (string) $this->config->get('tasks.api_key', '');
        if ($expected === '' || !hash_equals($expected, $request->getHeaderLine('X-Api-Key'))) {
            return $this->responses->json(['message' => 'A valid X-Api-Key header is required.'], 401);
        }

        return $handler->handle($request);
    }
}
```

Returning a response without calling `$handler` stops the request before it reaches the controller. The key comes from configuration rather than straight from the environment. Each `config/*.php` file becomes a configuration section, so create `config/tasks.php`:

```php
<?php

declare(strict_types=1);

return [
    'api_key' => env('TASKS_API_KEY', ''),
];
```

Then add the key to `.env`:

```sh
echo "TASKS_API_KEY=secret-key" >> .env
```

Try it:

```sh
curl http://127.0.0.1:3001/tasks
# [{"id":1,"title":"Read the XPSR docs"},{"id":2,"title":"Build an endpoint"}]

curl -H 'Accept: application/json' http://127.0.0.1:3001/tasks/99
# {"message":"Not Found"}

curl -X POST -H 'Content-Type: application/json' -H 'X-Api-Key: secret-key' \
     -d '{"title":"Ship it"}' http://127.0.0.1:3001/tasks
# {"id":3,"title":"Ship it"}   (201)

curl -X POST -H 'Content-Type: application/json' -d '{"title":"Ship it"}' http://127.0.0.1:3001/tasks
# {"message":"A valid X-Api-Key header is required."}   (401)
```

## 4. Add a console command

```sh
php xpsr make:command TaskCountCommand
```

The generator names the command `app:task-count` and prints the lines needed to register it. Replace the `execute()` body in `app/Console/Commands/TaskCountCommand.php`, and set the description:

```php
protected function configure(): void
{
    $this->setDescription('Show how many tasks exist.');
}

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $output->writeln('There are 2 tasks.');

    return self::SUCCESS;
}
```

Commands are registered explicitly, with no directory scanning. Add it to `app/Registry/CommandServiceRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Registry;

use App\Console\Commands\GreetCommand;
use App\Console\Commands\TaskCountCommand;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;

final class CommandServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->bind(GreetCommand::class);
        $this->container->bind(TaskCountCommand::class);
    }

    public function boot(): void
    {
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('app:greet', GreetCommand::class, 'Example application command with constructor injection.');
        $commands->register('app:task-count', TaskCountCommand::class, 'Show how many tasks exist.');
    }
}
```

```sh
php xpsr app:task-count     # There are 2 tasks.
php xpsr route:list         # the four routes, with the middleware on POST /tasks
```

## 5. Write tests

`tests/TestCase.php` sends requests through the full HTTP kernel (middleware, routing, error rendering) without a web server, and runs console commands. PHPUnit sets `APP_ENV=testing`, which loads overrides from `config/testing/`. Give the tests a known key in `config/testing/tasks.php`:

```php
<?php

declare(strict_types=1);

return [
    'api_key' => 'test-key',
];
```

Create `tests/Application/TaskTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Application;

use Tests\TestCase;

final class TaskTest extends TestCase
{
    public function testListsTasks(): void
    {
        $response = $this->get('/tasks');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, json_decode((string) $response->getBody(), true));
    }

    public function testShowsOneTaskOrNotFound(): void
    {
        self::assertSame(['id' => 1, 'title' => 'Read the XPSR docs'], json_decode((string) $this->get('/tasks/1')->getBody(), true));
        self::assertSame(404, $this->get('/tasks/99', ['Accept' => 'application/json'])->getStatusCode());
        self::assertSame(404, $this->get('/tasks/abc')->getStatusCode(), 'The id must be numeric.');
    }

    public function testCreatingATaskNeedsTheApiKey(): void
    {
        self::assertSame(401, $this->postJson('/tasks', ['title' => 'Ship it'])->getStatusCode());

        $response = $this->request('POST', '/tasks', ['X-Api-Key' => 'test-key', 'Accept' => 'application/json'], ['title' => 'Ship it']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id' => 3, 'title' => 'Ship it'], json_decode((string) $response->getBody(), true));
    }

    public function testATaskNeedsATitle(): void
    {
        $response = $this->request('POST', '/tasks', ['X-Api-Key' => 'test-key', 'Accept' => 'application/json'], ['title' => '  ']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['message' => 'Bad Request'], json_decode((string) $response->getBody(), true));
    }

    public function testTaskCountCommand(): void
    {
        [$status, $output] = $this->console('app:task-count');

        self::assertSame(0, $status);
        self::assertSame("There are 2 tasks.\n", $output);
    }
}
```

```sh
composer test
# OK (9 tests, 20 assertions)   (these 5 plus the skeleton's 4 examples)
```

`request()` sends an array body as JSON. `console()` takes arguments by name, for example `$this->console('app:greet', ['name' => 'Ada'])`. `swap($id, $replacement)` replaces a service for the rest of a test, for example a fake HTTP client in place of `Psr\Http\Client\ClientInterface`. `freezeTime('2026-01-31 09:00:00')` stops the application clock; see [CONTAINER.md](CONTAINER.md#the-clock).

## 6. Switch the router adapter

Routes are declared once and matched by an adapter. Switch to Sunrise:

```sh
php xpsr setup --router=sunrise --install-dependencies
```

```text
Router: sunrise. Preset: api.
Installed crystoline/xpsr-router-sunrise:^0.3.
Set ROUTER_ADAPTER=sunrise in .env.
Bootstrap check passed: 4 route(s); 3 without placeholders matched by Crystoline\Xpsr\Router\Sunrise\SunriseRouter.
```

Run `composer test` again: the same 9 tests pass without changing a route or controller. Switch back with `php xpsr setup --router=fastroute`.

## Next steps

- [HTTP.md](HTTP.md): request bodies, errors, logging, and how responses are sent.
- [ROUTING.md](ROUTING.md): groups, named URLs (`UrlGeneratorInterface`), constraints, and HEAD/OPTIONS behavior.
- [CONTAINER.md](CONTAINER.md): bindings, factories, and service providers (`php xpsr make:provider`).
- [CONSOLE.md](CONSOLE.md): every command and generator.
- [EVENTS.md](EVENTS.md): dispatching events and writing listeners.
- Optional packages: [the cache](../packages/cache/README.md), [the HTTP client](../packages/http-client/README.md), and [database connections and migrations](../packages/database/README.md).
- [DEPLOYMENT.md](DEPLOYMENT.md): running an XPSR application in production.
