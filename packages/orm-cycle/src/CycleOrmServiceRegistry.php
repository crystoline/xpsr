<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Cycle;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Audit\Attribute\Audited;
use Crystoline\Xpsr\Audit\Cycle\AuditedBehavior;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\QueryEvents;
use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Crystoline\Xpsr\Orm\Cycle\Auth\CycleUserProviderFactory;
use Crystoline\Xpsr\Orm\Cycle\Console\MakeEntityCommand;
use Crystoline\Xpsr\Orm\Cycle\Console\SchemaCacheCommand;
use Crystoline\Xpsr\Orm\Cycle\Console\SchemaClearCommand;
use Crystoline\Xpsr\Orm\Cycle\Console\SchemaCommand;
use Crystoline\Xpsr\Orm\Cycle\Http\ClearHeapMiddleware;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Cycle\Database\DatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\Collection\ArrayCollectionFactory;
use Cycle\ORM\Config\RelationConfig;
use Cycle\ORM\Entity\Behavior\EventDrivenCommandGenerator;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Registers Cycle ORM on XPSR's database connections, configured in config/cycle.php (see
 * CycleOptions):
 *
 *   Cycle\ORM\ORMInterface                                    the ORM: getRepository(), make(). Shared.
 *   Cycle\ORM\EntityManagerInterface                          persist(), delete(), run(). Shared.
 *   Cycle\Database\DatabaseManager                            Cycle's databases, one per XPSR connection. Shared.
 *   Cycle\ORM\SchemaInterface                                 the compiled schema, from the cache or the entities.
 *   Crystoline\Xpsr\Database\Schema\SchemaProviderInterface   the tables the entities need, for make:migration --diff
 *   Crystoline\Xpsr\Orm\Cycle\Http\ClearHeapMiddleware         for worker servers
 *
 * the "cycle" user provider for config/auth.php, and the cycle:schema, cycle:schema:cache,
 * cycle:schema:clear, and make:entity commands. Entities marked #[Audited] are recorded when
 * crystoline/xpsr-audit is installed. Requires Crystoline\Xpsr\Database\DatabaseServiceRegistry;
 * list it after the database provider.
 */
final class CycleOrmServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(CycleOptions::class, static function (ContainerInterface $container): CycleOptions {
            $config = $container->get(Configuration::class)->get('cycle', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The cycle configuration must be an array.');
            }

            return CycleOptions::from($config, $container->get(Application::class)->getRootDir());
        }, shared: true);
        $this->container->factory(DatabaseManager::class, static function (ContainerInterface $container): DatabaseManager {
            $events = $container->get(QueryEvents::class);
            $timezone = $container->get(Configuration::class)->get('app.timezone', 'UTC');

            return (new Databases(
                $container->get(ConnectionManager::class),
                $container->get(CycleOptions::class)->connection,
                $events->active() ? $events : null,
                is_string($timezone) && $timezone !== '' ? $timezone : 'UTC',
            ))->manager();
        }, shared: true);
        $this->container->alias(DatabaseProviderInterface::class, DatabaseManager::class);
        $this->container->factory(SchemaBuilder::class, static function (ContainerInterface $container): SchemaBuilder {
            $generators = [];
            if (self::installed(Audited::class) && self::installed(AuditedBehavior::class)) {
                $generators[] = new AuditedEntities();
            }

            return new SchemaBuilder($container->get(CycleOptions::class), $container->get(DatabaseManager::class), $generators);
        }, shared: true);
        $this->container->factory(SchemaCache::class, static fn(ContainerInterface $container): SchemaCache => new SchemaCache(
            $container->get(CycleOptions::class)->cacheFile,
        ), shared: true);
        $this->container->factory(SchemaInterface::class, static function (ContainerInterface $container): SchemaInterface {
            $options = $container->get(CycleOptions::class);
            $debug = $container->get(Configuration::class)->get('app.debug', false) === true;
            // With APP_DEBUG on, "auto" compiles from the entities, so edits apply at once.
            $cached = $options->cacheAuto && $debug ? null : $container->get(SchemaCache::class)->load();

            return new Schema($cached ?? $container->get(SchemaBuilder::class)->compile()->schema);
        }, shared: true);
        $this->container->factory(ORMInterface::class, static function (ContainerInterface $container): ORMInterface {
            $schema = $container->get(SchemaInterface::class);
            $factory = new Factory(
                $container->get(DatabaseManager::class),
                RelationConfig::getDefault(),
                new ContainerFactory($container),
                new ArrayCollectionFactory(),
            );

            return new ORM($factory, $schema, new EventDrivenCommandGenerator($schema, $container));
        }, shared: true);
        $this->container->alias(ORM::class, ORMInterface::class);
        $this->container->factory(EntityManagerInterface::class, static fn(ContainerInterface $container): EntityManagerInterface => new EntityManager(
            $container->get(ORMInterface::class),
        ), shared: true);
        $this->container->alias(EntityManager::class, EntityManagerInterface::class);
        $this->container->factory(EntitySchemaProvider::class, static fn(ContainerInterface $container): EntitySchemaProvider => new EntitySchemaProvider(
            $container->get(SchemaBuilder::class),
            $container->get(DatabaseManager::class),
            $container->get(CycleOptions::class)->connection,
        ), shared: true);
        $this->container->alias(SchemaProviderInterface::class, EntitySchemaProvider::class);
        $this->container->bind(ClearHeapMiddleware::class);
        // auth.users driver "cycle", resolved by the framework's AuthManager.
        $this->container->factory('auth.users.cycle', static fn(ContainerInterface $container): CycleUserProviderFactory => new CycleUserProviderFactory(
            $container->get(ORMInterface::class),
            $container->get(EntityManagerInterface::class),
        ), shared: true);
        foreach ([SchemaCommand::class, SchemaCacheCommand::class, SchemaClearCommand::class, MakeEntityCommand::class] as $command) {
            $this->container->bind($command);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('cycle:schema', SchemaCommand::class, 'Show the ORM schema compiled from the entities.');
        $commands->register('cycle:schema:cache', SchemaCacheCommand::class, 'Compile the ORM schema from the entities and cache it.');
        $commands->register('cycle:schema:clear', SchemaClearCommand::class, 'Remove the cached ORM schema.');
        $commands->register('make:entity', MakeEntityCommand::class, 'Create a Cycle entity class.');
    }

    /** Whether an optional package's class exists; a function, so analysis does not fold it. */
    private static function installed(string $class): bool
    {
        return class_exists($class);
    }
}
