<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Console\Commands\MakeProviderCommand;
use Crystoline\Xpsr\Registry\ConsoleServiceRegistry;
use Crystoline\Xpsr\Registry\EventServiceRegistry;
use Crystoline\Xpsr\Registry\FactoryRegistry;
use Crystoline\Xpsr\Registry\RouteServiceRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Server\MiddlewareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fixtures\Console\BufferedConsoleOutput;
use Crystoline\Xpsr\Tests\Fixtures\TemporaryDirectory;

final class GeneratorCommandsTest extends TestCase
{
    private string $root;
    private string $namespace;
    private ?Closure $autoloader = null;

    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create('generator app');
        // A unique namespace per test, because PHP cannot unload generated classes.
        $this->namespace = 'Generated' . bin2hex(random_bytes(6));
        foreach (['app', 'config', 'routes'] as $directory) {
            mkdir($this->root . '/' . $directory);
        }
        $this->writeConfiguration([]);
        file_put_contents($this->root . '/config/factory.php', '<?php return require ' . var_export(dirname(__DIR__, 2) . '/config/factory.php', true) . ';');
        file_put_contents($this->root . '/config/routing.php', '<?php return ["files" => ["routes/web.php"]];');
        file_put_contents($this->root . '/routes/web.php', '<?php return static function (): void {};');

        $prefix = $this->namespace . '\\';
        $source = $this->root . '/app/';
        $this->autoloader = static function (string $class) use ($prefix, $source): void {
            if (str_starts_with($class, $prefix)) {
                $file = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoloader);
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }
        TemporaryDirectory::remove($this->root);
    }

    /** @param list<class-string> $extraProviders */
    private function writeConfiguration(array $extraProviders): void
    {
        $registry = [FactoryRegistry::class, RouteServiceRegistry::class, EventServiceRegistry::class, ConsoleServiceRegistry::class, ...$extraProviders];
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export([
            'name' => 'Generated',
            'namespace' => $this->namespace,
            'source_dir' => 'app',
            'registry' => $registry,
        ], true) . ';');
    }

    /** @return array{int, string, string} status, stdout, and stderr with whitespace collapsed (error blocks wrap) */
    private function console(array $arguments, ?Application $application = null): array
    {
        $application ??= new Application($this->root);
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);
        $output = new BufferedConsoleOutput();
        $status = $application->getConsoleKernel()->run($input, $output);

        return [$status, $output->fetch(), trim((string) preg_replace('/\s+/', ' ', $output->errors()))];
    }

    private function contents(string $relativePath): string
    {
        return (string) file_get_contents($this->root . '/' . $relativePath);
    }

    public function testGeneratedClassesRunInTheApplication(): void
    {
        $ns = $this->namespace;
        $generate = [
            [['command' => 'make:controller', 'name' => 'Admin/UserController'], 'app/Http/Controller/Admin/UserController.php'],
            [['command' => 'make:controller', 'name' => 'PingController', '--invokable' => true], 'app/Http/Controller/PingController.php'],
            [['command' => 'make:middleware', 'name' => 'AuditRequests'], 'app/Http/Middleware/AuditRequests.php'],
            [['command' => 'make:command', 'name' => 'Reports/SendReportCommand'], 'app/Console/Commands/Reports/SendReportCommand.php'],
            [['command' => 'make:provider', 'name' => 'ReportServiceRegistry'], 'app/Registry/ReportServiceRegistry.php'],
            [['command' => 'make:event', 'name' => 'Users/UserRegistered'], 'app/Events/Users/UserRegistered.php'],
            [['command' => 'make:listener', 'name' => 'SendWelcomeEmail', '--event' => 'Users/UserRegistered'], 'app/Listeners/SendWelcomeEmail.php'],
            [['command' => 'make:listener', 'name' => 'AuditEverything'], 'app/Listeners/AuditEverything.php'],
        ];
        $application = new Application($this->root);
        foreach ($generate as [$arguments, $path]) {
            [$status, $stdout, $stderr] = $this->console($arguments, $application);
            self::assertSame(0, $status, $stderr);
            self::assertSame('', $stderr);
            self::assertStringStartsWith("Created {$path} ({$ns}\\", $stdout);
            self::assertStringContainsString('Next:', $stdout);
            self::assertFileExists($this->root . '/' . $path);
        }
        self::assertStringContainsString("[\\{$ns}\\Http\\Controller\\Admin\\UserController::class, 'index']", $this->console(
            ['command' => 'make:controller', 'name' => 'Admin/UserController', '--force' => true],
            $application,
        )[1]);

        // Route the generated controllers through the generated middleware and boot the generated provider.
        file_put_contents($this->root . '/routes/web.php', <<<PHP
<?php
return static function (\\Crystoline\\Xpsr\\Routing\\RouteCollection \$routes): void {
    \$routes->get('/users', [\\{$ns}\\Http\\Controller\\Admin\\UserController::class, 'index'])
        ->middleware(\\{$ns}\\Http\\Middleware\\AuditRequests::class);
    \$routes->get('/ping', \\{$ns}\\Http\\Controller\\PingController::class);
};
PHP);
        $provider = "{$ns}\\Registry\\ReportServiceRegistry";
        self::assertTrue(is_subclass_of($provider, ServiceRegistry::class));
        $this->writeConfiguration([$provider]);
        $kernel = (new Application($this->root))->getHttpKernel();
        $requests = new Psr17Factory();
        foreach (['/users' => 'UserController', '/ping' => 'PingController'] as $path => $controller) {
            $response = $kernel->handle($requests->createServerRequest('GET', 'http://localhost' . $path));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame(['controller' => $controller], json_decode((string) $response->getBody(), true));
        }
        self::assertTrue(is_subclass_of("{$ns}\\Http\\Middleware\\AuditRequests", MiddlewareInterface::class));

        $commandClass = "{$ns}\\Console\\Commands\\Reports\\SendReportCommand";
        $command = new $commandClass();
        self::assertInstanceOf(Command::class, $command);
        self::assertSame('app:send-report', $command->getName());
        self::assertSame('Command description.', $command->getDescription());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        self::assertSame("SendReportCommand completed.\n", $tester->getDisplay());

        // The generated listeners accept the generated event once listed in config/events.php.
        $event = "{$ns}\\Events\\Users\\UserRegistered";
        self::assertStringContainsString("public function __invoke(\\{$event} \$event): void", $this->contents('app/Listeners/SendWelcomeEmail.php'));
        self::assertStringContainsString('public function __invoke(object $event): void', $this->contents('app/Listeners/AuditEverything.php'));
        file_put_contents($this->root . '/config/events.php', '<?php return ' . var_export(['listeners' => [
            $event => ["{$ns}\\Listeners\\SendWelcomeEmail"],
            'stdClass' => ["{$ns}\\Listeners\\AuditEverything"],
        ]], true) . ';');
        $application = new Application($this->root);
        $application->boot();
        $instance = new $event();
        self::assertSame($instance, $application->get(EventDispatcherInterface::class)->dispatch($instance));
        $application->get(EventDispatcherInterface::class)->dispatch(new \stdClass());
        [$status, $stdout] = $this->console(['command' => 'event:list', '--json' => true], $application);
        self::assertSame(0, $status);
        self::assertSame(
            [[$event, "{$ns}\\Listeners\\SendWelcomeEmail"], ['stdClass', "{$ns}\\Listeners\\AuditEverything"]],
            array_map(static fn(array $row): array => [$row['event'], $row['listener']], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)),
        );
    }

    public function testListenerEventOption(): void
    {
        [$status, $stdout, $stderr] = $this->console(['command' => 'make:listener', 'name' => 'OnMissing', '--event' => 'Missing']);
        self::assertSame(0, $status);
        self::assertStringContainsString("{$this->namespace}\\Events\\Missing does not exist yet.", $stderr);
        self::assertStringContainsString("\\{$this->namespace}\\Events\\Missing::class => [\\{$this->namespace}\\Listeners\\OnMissing::class],", $stdout);

        [$status, , $stderr] = $this->console(['command' => 'make:listener', 'name' => 'OnRequest', '--event' => '\Psr\Http\Message\ServerRequestInterface']);
        self::assertSame(0, $status);
        self::assertSame('', $stderr);
        self::assertStringContainsString('__invoke(\Psr\Http\Message\ServerRequestInterface $event)', $this->contents('app/Listeners/OnRequest.php'));

        foreach (['../Escape', '\Not Valid', '\\'] as $invalid) {
            [$status, $stdout, $stderr] = $this->console(['command' => 'make:listener', 'name' => 'Bad', '--event' => $invalid]);
            self::assertSame(2, $status, $invalid);
            self::assertSame('', $stdout);
            self::assertStringContainsString('--event', $stderr);
        }
        self::assertFileDoesNotExist($this->root . '/app/Listeners/Bad.php');
    }

    public function testExistingFilesAreReportedAndReplacedOnlyWithForce(): void
    {
        $path = 'app/Http/Middleware/Audit.php';
        self::assertSame(0, $this->console(['command' => 'make:middleware', 'name' => 'Audit'])[0]);
        file_put_contents($this->root . '/' . $path, 'sentinel');

        [$status, $stdout, $stderr] = $this->console(['command' => 'make:middleware', 'name' => 'Audit']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('already exists', $stderr);
        self::assertStringContainsString('--force', $stderr);
        self::assertSame('sentinel', $this->contents($path));

        [$status, $stdout] = $this->console(['command' => 'make:middleware', 'name' => 'Audit', '--force' => true]);
        self::assertSame(0, $status);
        self::assertStringStartsWith("Replaced {$path}", $stdout);
        self::assertStringContainsString('final class Audit implements MiddlewareInterface', $this->contents($path));
    }

    public static function invalidNames(): iterable
    {
        foreach (['make:controller', 'make:middleware', 'make:command', 'make:provider', 'make:event', 'make:listener'] as $command) {
            yield "{$command} parent directory" => [$command, '../Escape'];
            yield "{$command} absolute" => [$command, '/tmp/Escape'];
            yield "{$command} reserved" => [$command, 'Admin/Class'];
            yield "{$command} space" => [$command, 'Two Words'];
        }
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesCreateNothing(string $command, string $name): void
    {
        [$status, $stdout, $stderr] = $this->console(['command' => $command, 'name' => $name]);
        self::assertSame(2, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('class name', $stderr);
        self::assertSame([], TemporaryDirectory::files($this->root . '/app'));
    }

    public function testMakeCommandOptions(): void
    {
        [$status, , $stderr] = $this->console(['command' => 'make:command', 'name' => 'Bad', '--command' => 'Bad Name']);
        self::assertSame(2, $status);
        self::assertStringContainsString('--command', $stderr);
        [$status, , $stderr] = $this->console(['command' => 'make:command', 'name' => 'Bad', '--description' => "two\nlines"]);
        self::assertSame(2, $status);
        self::assertStringContainsString('single line', $stderr);
        [$status] = $this->console(['command' => 'make:command', 'name' => '_9']);
        self::assertSame(2, $status, 'A class name that cannot produce a console name needs --command.');
        self::assertSame([], TemporaryDirectory::files($this->root . '/app'));

        // Quotes and backslashes in options must survive as PHP string literals.
        [$status, $stdout, $stderr] = $this->console([
            'command' => 'make:command',
            'name' => 'Export',
            '--command' => 'about',
            '--description' => "It's C:\\temp \\' safe",
        ]);
        self::assertSame(0, $status);
        self::assertStringContainsString('"about" is already registered', $stderr);
        self::assertStringContainsString("->register('about', \\{$this->namespace}\\Console\\Commands\\Export::class", $stdout);
        $class = "{$this->namespace}\\Console\\Commands\\Export";
        $command = new $class();
        self::assertSame('about', $command->getName());
        self::assertSame("It's C:\\temp \\' safe", $command->getDescription());
    }

    public function testNameIsPromptedInteractivelyAndRequiredOtherwise(): void
    {
        [$status, $stdout, $stderr] = $this->console(['command' => 'make:provider']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Not enough arguments', $stderr);

        $application = new Application($this->root);
        $application->boot();
        $tester = new CommandTester($application->get(MakeProviderCommand::class));
        $tester->setInputs(['PromptedServiceRegistry']);
        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertStringContainsString('Provider class name', $tester->getDisplay());
        self::assertFileExists($this->root . '/app/Registry/PromptedServiceRegistry.php');
    }

    public function testListShowsGeneratorsWithoutConstructingThem(): void
    {
        [$status, $stdout] = $this->console(['command' => 'list', 'namespace' => 'make']);
        self::assertSame(0, $status);
        foreach (['make:command', 'make:controller', 'make:event', 'make:listener', 'make:middleware', 'make:provider'] as $name) {
            self::assertStringContainsString($name, $stdout);
        }
        // An invalid generator configuration is only reported when a generator runs.
        file_put_contents($this->root . '/config/app.php', '<?php return ' . var_export([
            'namespace' => 'Not Valid',
            'registry' => [FactoryRegistry::class, RouteServiceRegistry::class, ConsoleServiceRegistry::class],
        ], true) . ';');
        self::assertSame(0, $this->console(['command' => 'list'])[0]);
        [$status, , $stderr] = $this->console(['command' => 'make:provider', 'name' => 'Anything']);
        self::assertSame(1, $status);
        self::assertStringContainsString('application namespace', $stderr);
    }

    public function testGeneratedFilesFollowTheProjectStyleAndPassSyntaxChecks(): void
    {
        foreach ([
            ['command' => 'make:controller', 'name' => 'StyleController'],
            ['command' => 'make:controller', 'name' => 'InvokableController', '--invokable' => true],
            ['command' => 'make:middleware', 'name' => 'StyleMiddleware'],
            ['command' => 'make:command', 'name' => 'StyleCommand', '--description' => "Quote ' check"],
            ['command' => 'make:provider', 'name' => 'StyleServiceRegistry'],
            ['command' => 'make:event', 'name' => 'StyleEvent'],
            ['command' => 'make:listener', 'name' => 'StyleListener', '--event' => 'StyleEvent'],
        ] as $arguments) {
            self::assertSame(0, $this->console($arguments)[0]);
        }
        foreach (TemporaryDirectory::files($this->root . '/app') as $file) {
            $path = $this->root . '/app/' . $file;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $lint, $code);
            self::assertSame(0, $code, implode("\n", $lint));
            $contents = (string) file_get_contents($path);
            self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\nnamespace {$this->namespace}\\", $contents);
            self::assertStringEndsWith("}\n", $contents);
            self::assertStringNotContainsString("\r", $contents);
            self::assertDoesNotMatchRegularExpression('/[ \t]+$/m', $contents, "{$file} has trailing whitespace.");
        }
    }
}
