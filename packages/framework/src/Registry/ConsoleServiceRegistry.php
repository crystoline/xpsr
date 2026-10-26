<?php

declare(strict_types=1);

namespace Crystoline\Registry;

use Crystoline\Application;
use Crystoline\Configuration;
use Crystoline\Console\CommandRegistry;
use Crystoline\Console\Commands\AboutCommand;
use Crystoline\Console\Commands\MakeCommandCommand;
use Crystoline\Console\Commands\MakeControllerCommand;
use Crystoline\Console\Commands\MakeMiddlewareCommand;
use Crystoline\Console\Commands\MakeProviderCommand;
use Crystoline\Console\Commands\RouteListCommand;
use Crystoline\Console\Commands\ServeCommand;
use Crystoline\Console\Commands\SetupCommand;
use Crystoline\Console\ConsoleKernel;
use Crystoline\Console\Generator\StubGenerator;
use Crystoline\Console\Setup\ComposerPackageInspector;
use Crystoline\Console\Setup\PackageInspectorInterface;
use Crystoline\ServiceContainer\ServiceRegistry;
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
