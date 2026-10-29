<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Eloquent;

use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Orm\Eloquent\Auth\EloquentUserProviderFactory;
use Crystoline\Xpsr\Orm\Eloquent\Console\MakeModelCommand;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Psr\Container\ContainerInterface;

/**
 * Makes Eloquent models work on XPSR's database connections (config/database.php): each model
 * uses the XPSR connection of the same name as its $connection, or the default one. Schema
 * changes are XPSR migrations (php xpsr make:migration). Model events and observers work.
 *
 *   Crystoline\Xpsr\Orm\Eloquent\ConnectionResolver   Eloquent's connections, by XPSR name
 *   Illuminate\Database\ConnectionInterface           the default connection, for the query builder
 *
 * and the make:model command, and the "eloquent" user provider for config/auth.php. List it in
 * app.registry after Crystoline\Xpsr\Database\DatabaseServiceRegistry.
 */
final class EloquentServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(ConnectionResolver::class, static fn(ContainerInterface $container): ConnectionResolver => new ConnectionResolver(
            $container->get(ConnectionManager::class),
        ), shared: true);
        $this->container->alias(ConnectionResolverInterface::class, ConnectionResolver::class);
        $this->container->factory(
            ConnectionInterface::class,
            static fn(ContainerInterface $container): ConnectionInterface => $container->get(ConnectionResolver::class)->connection(),
        );
        $this->container->factory('auth.users.eloquent', static fn(): EloquentUserProviderFactory => new EloquentUserProviderFactory(), shared: true);
        $this->container->bind(MakeModelCommand::class);
    }

    public function boot(): void
    {
        // Eloquent models find their connection and event dispatcher statically. Nothing connects
        // until a model runs a query.
        Model::setConnectionResolver($this->container->get(ConnectionResolver::class));
        Model::setEventDispatcher(new Dispatcher(new Container()));
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('make:model', MakeModelCommand::class, 'Create an Eloquent model class.');
        }
    }
}
