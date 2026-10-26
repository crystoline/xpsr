<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Application;
use Crystoline\Console\CommandRegistry;
use Crystoline\Console\ConsoleKernel;
use Crystoline\Routing\RouteCollection;
use Crystoline\ServiceContainer\ServiceContainer;
use Crystoline\Contracts\Routing\RouterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Fixtures\Console\BufferedConsoleOutput;

final class ConsoleKernelTest extends TestCase
{
    /** @return array{int, string, string} */
    private function runConsole(ConsoleKernel $kernel, array $arguments): array
    {
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);
        $output = new BufferedConsoleOutput();
        $status = $kernel->run($input, $output);
        return [$status, $output->fetch(), $output->errors()];
    }

    public function testListAndAboutDoNotResolveHttpOrRoutesOrStartSessions(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->boot();
        $sessionStatus = session_status();
        $calls = 0;
        foreach ([ServerRequestInterface::class, RequestHandlerInterface::class, RouterInterface::class, RouteCollection::class] as $id) {
            $app->getContainer()->factory($id, static function () use (&$calls): never {
                ++$calls;
                throw new \RuntimeException('HTTP or route service was constructed.');
            });
        }
        $app->getConfig()->set('app.key', 'secret-sentinel');
        $app->getConfig()->set('database.password', 'secret-sentinel');
        $kernel = $app->getConsoleKernel();
        $this->expectOutputString('');
        foreach ([['command' => 'list'], ['command' => 'help'], ['command' => 'about', '--json' => true]] as $arguments) {
            [$status, $stdout, $stderr] = $this->runConsole($kernel, $arguments);
            self::assertSame(0, $status, $stderr);
            self::assertSame('', $stderr);
            self::assertNotSame('', $stdout);
            self::assertStringNotContainsString('secret-sentinel', $stdout);
        }
        self::assertSame(0, $calls);
        self::assertSame($sessionStatus, session_status());
        self::assertSame($kernel, $app->getConsoleKernel());
    }

    public function testCommandsResolveLazilyAndFreshRunsDoNotRetainHelpState(): void
    {
        $container = new ServiceContainer();
        $commands = new CommandRegistry();
        $calls = 0;
        $container->instance('prefix', 'Injected');
        $container->factory('greet.command', static function ($container) use (&$calls): Command {
            ++$calls;
            return new class ($container->get('prefix')) extends Command {
                public function __construct(private readonly string $prefix)
                {
                    parent::__construct('original:name');
                }
                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED)
                        ->addOption('upper', null, InputOption::VALUE_NONE);
                }
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $text = $this->prefix . ' ' . $input->getArgument('name');
                    $output->writeln($input->getOption('upper') ? strtoupper($text) : $text);
                    return 7;
                }
            };
        });
        $commands->register('app:greet', 'greet.command', 'Test injected command.', ['greet']);
        $kernel = new ConsoleKernel($container, $commands);
        [$status, $stdout] = $this->runConsole($kernel, ['command' => 'list']);
        self::assertSame(0, $status);
        self::assertStringContainsString('app:greet', $stdout);
        self::assertSame(0, $calls);
        [$status, $stdout] = $this->runConsole($kernel, ['command' => 'help', 'command_name' => 'app:greet']);
        self::assertSame(0, $status);
        self::assertStringContainsString('--upper', $stdout);
        self::assertSame(1, $calls);
        [$status, $stdout, $stderr] = $this->runConsole($kernel, ['command' => 'greet', 'name' => 'Ada', '--upper' => true]);
        self::assertSame(7, $status);
        self::assertSame("INJECTED ADA\n", $stdout);
        self::assertSame('', $stderr);
        self::assertSame(2, $calls);
    }

    public function testUnknownCommandAndArgumentErrorsGoToStderr(): void
    {
        $kernel = (new Application(dirname(__DIR__, 2)))->getConsoleKernel();
        foreach ([['command' => 'missing:command'], ['command' => 'app:greet', '--unknown' => true]] as $arguments) {
            [$status, $stdout, $stderr] = $this->runConsole($kernel, $arguments);
            self::assertNotSame(0, $status);
            self::assertSame('', $stdout);
            self::assertNotSame('', $stderr);
        }
    }

    public function testInvalidCommandServiceAndThrownErrorsReturnFailure(): void
    {
        $container = new ServiceContainer();
        $commands = new CommandRegistry();
        $container->instance('invalid.command', new \stdClass());
        $container->factory('broken.command', static function (): never {
            throw new \Error('Failure sentinel');
        });
        $commands->register('bad:type', 'invalid.command');
        $commands->register('bad:error', 'broken.command');
        $kernel = new ConsoleKernel($container, $commands);
        foreach (['bad:type' => 'must extend', 'bad:error' => 'Failure sentinel'] as $name => $message) {
            [$status, $stdout, $stderr] = $this->runConsole($kernel, ['command' => $name]);
            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString($message, $stderr);
        }
    }

    public function testExampleApplicationCommandUsesInjectedConfiguration(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getConfig()->set('app.name', 'Example <project>');
        [$status, $stdout, $stderr] = $this->runConsole($app->getConsoleKernel(), ['command' => 'app:greet', 'name' => '<person>']);
        self::assertSame(0, $status);
        self::assertSame("Hello, <person>! Welcome to Example <project>.\n", $stdout);
        self::assertSame('', $stderr);
    }
}
