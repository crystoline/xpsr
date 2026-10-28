# XPSR console

The project-root `xpsr` executable uses the shared bootstrap and standalone Symfony Console. Framework and application commands are registered explicitly through providers and resolved through the PSR-11 container.

## Available commands

```sh
php xpsr list
php xpsr help route:list
php xpsr about
php xpsr about --json
php xpsr route:list
php xpsr route:list --method=GET --path=/hello --json
php xpsr serve --host=127.0.0.1 --port=3001
php xpsr app:greet Ada
php xpsr setup
php xpsr setup --router=sunrise --preset=api --install-dependencies --no-interaction
php xpsr make:controller Admin/UserController
php xpsr make:controller PingController --invokable
php xpsr make:middleware EnsureJsonRequest
php xpsr make:command SendReportCommand --command=app:send-report --description="Send the daily report."
php xpsr make:provider BillingServiceRegistry
php xpsr make:event UserRegistered
php xpsr make:listener SendWelcomeEmail --event=UserRegistered
php xpsr event:list
php xpsr make:input CreateTask
```

`php xpsr` defaults to `list`. Standard help, verbosity, non-interactive mode (`--no-interaction`), and shell completion are supplied by Symfony Console. `composer serve` delegates to `php xpsr serve`. The executable resolves its bootstrap from its own directory and works when invoked by absolute path from another working directory.

`about` reports an explicit selection of application/runtime settings: framework, framework version, application name, environment, debug status, PHP version, configured router, and root directory. It does not dump credentials, resolve the router, load route files, or perform a full deployment health check. The version is the installed `crystoline/xpsr-framework` version as Composer recorded it, such as `0.2.0` for a release or `0.2.x-dev` for a development install; `php xpsr --version` shows the same. It reads `development` only if Composer has no record of the framework.

`route:list` reads the shared route collection, so its output is independent of the selected adapter. It lists declared methods, path, name, handler, and inherited/route middleware without instantiating controllers or middleware. `--method` filters declared methods case-insensitively; `--path` and `--name` use case-sensitive substring matches. Filters combine with AND. HEAD fallback and automatic OPTIONS are implicit and do not create extra rows. `--json` returns an array (including an empty array when nothing matches).

`event:list` shows each event listener with its priority, in the order listeners run. `--event` filters event class names by a case-insensitive substring, and `--json` returns an array. It and the `make:event` and `make:listener` generators come from `EventServiceRegistry`; see [EVENTS.md](EVENTS.md).

## Set up a project

`php xpsr setup` applies two explicit choices and then checks that the application boots:

| Option | Values | What changes |
|---|---|---|
| `--router` | `fastroute` (default), `sunrise` | `ROUTER_ADAPTER` in `.env`. Installs the adapter package when it is not yet a production dependency. |
| `--preset` | `web`, `api` | `files` in `config/routing.php`: `web` loads `routes/api.php` and `routes/web.php`; `api` loads only `routes/api.php`. |

In a terminal, setup asks for each choice, offering the current one as the default. Options you omit with `--no-interaction` keep the current setting, so running setup again changes nothing. Run it after `composer create-project`, not from a Composer script: setup may call Composer itself.

**Steps.** Setup validates the options, plans its file changes, installs a missing package, writes the files, and then runs a bootstrap check:

1. **Plan.** `.env` is created from `.env.example` when missing. An existing `.env` keeps every line except `ROUTER_ADAPTER`, which is replaced or appended. `config/routing.php` is rewritten only when it is still the unmodified skeleton file for one of the presets. If you have customized it, setup stops, changes nothing, and prints the `files` value to set by hand. A `.env` or `config/routing.php` that is a symbolic link is never replaced.
2. **Install.** Each adapter is a package: `crystoline/xpsr-router-fastroute` or `crystoline/xpsr-router-sunrise`. A package counts as present only if it is in the `require` section of `composer.json` and installed; a `require-dev` install would be missing from a `--no-dev` deployment. If it is missing, setup runs `composer require <package> --update-with-dependencies` when you pass `--install-dependencies` or confirm the prompt; otherwise it stops and prints the command. `--update-with-dependencies` lets Composer adjust the adapter's own dependencies, for example move `psr/http-message` from 2.x to the 1.x that Sunrise requires; your direct requirements stay locked. If Composer fails, no files are changed. `--composer=PATH` selects a Composer executable or `.phar`; otherwise `COMPOSER_BINARY` or `composer` on the `PATH` is used. Composer's output goes to stderr. Setup never removes the adapter you switched away from.
3. **Write and check.** Files are written atomically. A fresh PHP process then boots the application, resolves the configured router, and matches every route without placeholders. Route handlers and middleware are not executed. If the application does not boot, a route does not match, or the effective adapter or route files differ from the choice, every file setup wrote is restored. A different effective adapter usually means `ROUTER_ADAPTER` is set in the shell environment. A package installed during that run stays installed, and the error says how to remove it.

`--dry-run` prints the planned Composer command and file changes without performing them. Exit codes: 0 on success or when nothing needs to change; 2 for an unknown router or preset; 1 for a refused change, missing permission to install, a Composer failure, or a failed bootstrap check.

**Custom adapters.** Setup offers only the adapters that pass the shared routing suite. To use your own `RouterInterface` implementation, set `ROUTER_ADAPTER` (or `routing.adapter`) to its class name as described in `ROUTING.md`. Setup leaves a custom adapter unchanged unless you pass `--router`.

## Generate application classes

The `make:*` commands render XPSR stubs into the application source directory:

| Command | Namespace / directory | Generated class |
|---|---|---|
| `make:controller` | `App\Http\Controller` / `app/Http/Controller/` | Final class with an injected `ResponseBuilder` and an `index()` action; `--invokable` creates a single-action `__invoke()` controller. |
| `make:middleware` | `App\Http\Middleware` / `app/Http/Middleware/` | PSR-15 `MiddlewareInterface` implementation that passes the request on. |
| `make:command` | `App\Console\Commands` / `app/Console/Commands/` | Symfony `Command` subclass. `--command` sets the console name; otherwise `SendReportCommand` becomes `app:send-report`. `--description` sets the description. |
| `make:provider` | `App\Registry` / `app/Registry/` | `ServiceRegistry` subclass with empty `register()` and `boot()` hooks. |
| `make:event` | `App\Events` / `app/Events/` | Final event class with an empty constructor for its readonly properties. |
| `make:input` | `App\Http\Input` / `app/Http/Input/` | Input class for request validation, with trimming and empty-to-null filters and an example rule. Registered by `crystoline/xpsr-validation`. |
| `make:listener` | `App\Listeners` / `app/Listeners/` | Final class with `__invoke()`. `--event=UserRegistered` types the parameter as `App\Events\UserRegistered`; a name starting with `\` is used as written. Without `--event` the parameter is `object`. Warns on stderr if the event class does not exist yet. |

The name argument is relative to the namespace in the table. `Admin/UserController` and `Admin\UserController` both produce `App\Http\Controller\Admin\UserController` in `app/Http/Controller/Admin/UserController.php`; missing subdirectories are created. Each part of the name must be an ASCII PHP identifier and must not be a reserved PHP word or a Windows device name such as `Con`. The generator does not add or remove suffixes.

Generators never register what they create, because XPSR has no directory scanning. After writing a file they print the next step: a route line for controllers, `->middleware()` or `app.middleware` for middleware, provider registration lines for commands, the `app.registry` entry for providers, and the `config/events.php` line for listeners. `make:command` warns on stderr if the chosen console name is already registered.

The application namespace and directory come from `app.namespace` (default `App`) and `app.source_dir` (default `app`) in `config/app.php`. They must match the PSR-4 entry in `composer.json`. `app.source_dir` must be a relative path, and the directory must already exist.

**Safety and conflicts.** Generators write only inside the source directory. Paths are checked after resolving symbolic links, so a link inside `app/` that points elsewhere is refused, and a target that is itself a symbolic link is never replaced. An existing file is reported as a conflict and left unchanged; pass `--force` to replace it. New files are created exclusively, and forced replacements are written to a temporary file beside the target and then renamed, so an interrupted write never leaves a partial class.

**Interaction and exit codes.** In an interactive terminal, a missing name is asked for. With `--no-interaction`, a missing name fails as a usage error. Success returns 0; an invalid name or option returns 2 (`INVALID`) and writes nothing; a conflict or filesystem failure returns 1 (`FAILURE`). Messages go to stderr. Generated files pass PER Coding Style and PHPStan at its highest level.

Stubs live in `packages/framework/src/Console/Resources/stubs/`. Application-level stub overrides are not supported yet.

## Add an application command

Put commands in `app/Console/Commands/` (or start one with `php xpsr make:command`), extend Symfony's `Command`, and use constructor injection:

```php
namespace App\Console\Commands;

use Crystoline\Xpsr\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WelcomeCommand extends Command
{
    public function __construct(private readonly Configuration $configuration)
    {
        parent::__construct('app:welcome');
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf(
            'Hello, %s from %s.',
            $input->getArgument('name'),
            $this->configuration->get('app.name'),
        ), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
```

Use an eager provider to bind services in `register()` and add command metadata in `boot()`. All eager providers have registered their services before boot hooks execute:

```php
namespace App\Registry;

use App\Console\Commands\WelcomeCommand;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;

final class WelcomeServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->bind(WelcomeCommand::class);
    }

    public function boot(): void
    {
        $this->container->get(CommandRegistry::class)->register(
            'app:welcome',
            WelcomeCommand::class,
            'Welcome a user.',
            aliases: ['welcome'],
        );
    }
}
```

Add this provider to `app.registry` in `config/app.php`, keeping `Crystoline\Xpsr\Registry\ConsoleServiceRegistry`. The included `App\Registry\CommandServiceRegistry` and `app:greet` command demonstrate this pattern.

Command registration specifies the name, service ID, description, and optional aliases. This metadata is authoritative, including when the command class declares a different name or aliases. Names are lowercase words separated by colons, with digits, underscores, and hyphens permitted after each word's first letter. Duplicate names/aliases and Symfony's reserved names (`list`, `help`, `completion`, `_complete`) fail explicitly.

There is no automatic directory scanning or attribute discovery. Plain invokable objects are not command services in this initial API; extend Symfony's `Command`. Use a factory binding for services with constructor dependencies that cannot be autowired. To change a built-in command's behavior, replace its service binding rather than registering its name twice.

Default command listing uses lazy metadata, so unrelated command constructors are not called. Running a command or asking for its detailed help resolves that command and its dependencies. Symfony's richer machine-readable command descriptions may resolve commands to inspect their input definitions. `route:list` therefore loads route files when selected; `list` and `about` do not. Providers should register command metadata in an eager boot hook; a deferred provider whose services have never been requested cannot advertise commands by executing its boot hook.

## Lifecycle, output, and testing

`Application::getConsoleKernel()` boots providers once and returns the console kernel. `ConsoleKernel::run($input, $output)` returns an integer; the project executable alone calls `exit`. Each run builds a fresh Symfony Console application so help/input state does not leak into the next invocation. Commands are resolved according to their container lifetime; explicitly shared commands and shared application services retain their own state.

```php
$application = require __DIR__ . '/bootstrap/app.php';
$input = new \Symfony\Component\Console\Input\ArrayInput(['command' => 'about', '--json' => true]);
$input->setInteractive(false);
$output = new \Symfony\Component\Console\Output\BufferedOutput();
$status = $application->getConsoleKernel()->run($input, $output);
$json = $output->fetch();
```

Normal results go to stdout. Symfony command/usage errors go to stderr when using the real console or a `ConsoleOutputInterface` implementation. A plain `BufferedOutput` captures a combined stream. Bootstrap failures are caught by the executable, printed to stderr, and return 1. CLI exceptions are developer-facing; HTTP error rendering is a separate boundary.

Use `Command::SUCCESS` (0), `Command::FAILURE` (1), or `Command::INVALID` (2) in application commands. Returned command statuses propagate to the caller; Symfony parser and lookup failures return nonzero. The supplied `serve` and `route:list` option validation returns 2. `Symfony\Component\Console\Tester\CommandTester` can test individual commands; kernel and subprocess tests cover integration, stderr, and exit status.

The default console bootstrap does not construct HTTP requests, resolve the HTTP kernel, or start sessions. A custom provider or command can introduce such side effects, so keep provider registration lightweight. This milestone does not establish isolation between multiple application instances using global helpers.

## Development server

`serve` launches the current PHP CLI binary with an argument array, a `public/` document root, and XPSR's development router script. Static public files pass through to PHP's server; other URLs reach `public/index.php`, including routes with dots in their paths. Hidden files and symlinks escaping `public/` cannot bypass the front controller. Host/port inputs are validated before launching the child process. A missing front controller or occupied port returns failure.

The default address is `127.0.0.1:3001`. Use `--host=0.0.0.0` when deliberately serving through a container port mapping; IPv6 literals such as `--host=::1` are also accepted. This is a development server, not a production server.

On Unix, `serve` requires `pcntl` to handle Ctrl+C/SIGINT and SIGTERM and stop the child process. The bundled Docker image and CI extension list include it. Interrupted commands return 130 or 143, respectively. Child stdout/stderr are forwarded without retaining a growing history in the parent process. Worker mode is disabled to keep child cleanup predictable.

The live server tests cover Linux, including application paths with spaces and signal cleanup. Native Windows server execution/shutdown is not verified by this suite; other console commands are exercised in Linux containers from the Windows workspace.

## Remaining developer tooling

A standalone `xpsr new` installer, application stub overrides, and configuration/route caches are subsequent work. Database, queue, and scheduling commands belong to their future integrations.

Upstream reference: [Symfony Console documentation](https://symfony.com/doc/7.4/console.html). XPSR provides its own registration and lifecycle; Symfony FrameworkBundle configuration and automatic service discovery do not apply here.
