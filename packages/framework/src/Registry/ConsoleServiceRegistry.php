<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Console\Commands\AboutCommand;
use Crystoline\Xpsr\Console\Commands\MakeCommandCommand;
use Crystoline\Xpsr\Console\Commands\MakeControllerCommand;
use Crystoline\Xpsr\Console\Commands\MakeMiddlewareCommand;
use Crystoline\Xpsr\Console\Commands\MakeProviderCommand;
use Crystoline\Xpsr\Console\Commands\RouteListCommand;
use Crystoline\Xpsr\Console\Commands\ServeCommand;
use Crystoline\Xpsr\Console\Commands\SetupCommand;
use Crystoline\Xpsr\Console\ConsoleKernel;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Crystoline\Xpsr\Console\Setup\ComposerPackageInspector;
use Crystoline\Xpsr\Console\Setup\PackageInspectorInterface;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

final class ConsoleServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(CommandRegistry::class, static function (): CommandRegistry {
            $commands = new CommandRegistry();
            $commands->register('about', AboutCommand::class, 'Show application and runtime information.');
            $commands->register('make:command', MakeCommandCommand::class, 'Create a console command class.');
            $commands->register('make:controller', MakeControllerCommand::class, 'Create a controller class.');
            $commands->register('make:middleware', MakeMiddlewareCommand::class, 'Create a PSR-15 middleware class.');
            $commands->register('make:provider', MakeProviderCommand::class, 'Create a service provider (registry) class.');
            $commands->register('route:list', RouteListCommand::class, 'List portable route definitions.');
            $commands->register('serve', ServeCommand::class, 'Run the PHP development server with public/ as its document root.');
            $commands->register('setup', SetupCommand::class, 'Choose the router adapter and preset, then check that the application boots.');
            return $commands;
        }, shared: true);
        $this->container->factory(
            PackageInspectorInterface::class,
            static fn(ContainerInterface $container): ComposerPackageInspector => new ComposerPackageInspector($container->get(Application::class)->getRootDir()),
        );
        $this->container->factory(StubGenerator::class, static function (ContainerInterface $container): StubGenerator {
            $config = $container->get(Configuration::class);
            $namespace = $config->get('app.namespace', 'App');
            $sourceDir = $config->get('app.source_dir', 'app');
            if (!is_string($namespace) || !is_string($sourceDir)) {
                throw new InvalidArgumentException('app.namespace and app.source_dir must be strings.');
            }
            return new StubGenerator($container->get(Application::class)->getRootDir(), $namespace, $sourceDir);
        }, shared: true);
        $this->container->singleton(ConsoleKernel::class);
    }
}
