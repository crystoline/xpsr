<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Seeder\Console\MakeSeederCommand;
use Crystoline\Xpsr\Database\Seeder\Console\SeedCommand;
use Crystoline\Xpsr\Database\Seeder\Console\SeedListCommand;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Registers seeding, configured in config/seeds.php (see SeedOptions):
 *
 *   Crystoline\Xpsr\Database\Seeder\SeedRunner    runs sets and seeder classes. Shared.
 *   Crystoline\Xpsr\Database\Seeder\SeedOptions   the sets. Shared.
 *
 * and the commands seed, seed:list, and make:seeder when the console is part of the
 * application. List it in app.registry after Crystoline\Xpsr\Database\DatabaseServiceRegistry.
 */
final class SeederServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(SeedOptions::class, static function (ContainerInterface $container): SeedOptions {
            $config = $container->get(Configuration::class)->get('seeds', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The seeds configuration must be an array.');
            }

            return SeedOptions::fromArray($config);
        }, shared: true);
        $this->container->factory(SeedRunner::class, static function (ContainerInterface $container): SeedRunner {
            $config = $container->get(Configuration::class);
            $environment = $config->get('app.env', 'production');
            $locale = $config->get('app.faker_locale', 'en_US');

            return new SeedRunner(
                $container,
                $container->get(ConnectionManager::class),
                $container->get(SeedOptions::class),
                is_string($environment) && $environment !== '' ? $environment : 'production',
                is_string($locale) && $locale !== '' ? $locale : 'en_US',
            );
        }, shared: true);
        foreach ([SeedCommand::class, SeedListCommand::class, MakeSeederCommand::class] as $command) {
            $this->container->bind($command);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('seed', SeedCommand::class, 'Load seed data: the default set, or the sets named.');
        $commands->register('seed:list', SeedListCommand::class, 'List the seed sets, their seeders, and whether this environment may run them.');
        $commands->register('make:seeder', MakeSeederCommand::class, 'Create a seeder class.');
    }
}
