<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Orm\Doctrine;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Schema\SchemaProviderInterface;
use Crystoline\Xpsr\Orm\Doctrine\Console\ValidateSchemaCommand;
use Crystoline\Xpsr\Orm\Doctrine\Http\ClearEntityManagerMiddleware;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Console\Command;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Persistence\Mapping\Driver\ClassNames;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Registers Doctrine ORM on XPSR's database connection:
 *
 *   Doctrine\ORM\EntityManagerInterface                       the entity manager (also EntityManager)
 *   Crystoline\Xpsr\Database\Schema\SchemaProviderInterface   the entity schema, for make:migration --diff
 *   Http\ClearEntityManagerMiddleware                         for worker servers
 *
 * and Doctrine's read-only and cache commands under php xpsr. Requires
 * Crystoline\Xpsr\Database\DatabaseServiceRegistry. Options: config/orm.php, see OrmOptions.
 */
final class DoctrineOrmServiceRegistry extends ServiceRegistry
{
    /**
     * Doctrine commands offered under php xpsr. The schema-tool commands are left out on
     * purpose: schema changes go through migrations (make:migration --diff). Proxy generation
     * is not needed because PHP 8.4 lazy objects replace proxy classes.
     */
    public const COMMANDS = [
        'orm:info' => [Command\InfoCommand::class, 'Show the mapped entity classes.'],
        'orm:mapping:describe' => [Command\MappingDescribeCommand::class, 'Show the mapping of an entity class.'],
        'orm:validate-schema' => [ValidateSchemaCommand::class, 'Check the entity mapping, and that the database matches it.'],
        'orm:run-dql' => [Command\RunDqlCommand::class, 'Run a DQL query and show the result.'],
        'orm:clear-cache:metadata' => [Command\ClearCache\MetadataCommand::class, 'Clear the cached entity mapping.'],
        'orm:clear-cache:query' => [Command\ClearCache\QueryCommand::class, 'Clear the cached DQL-to-SQL translations.'],
        'orm:clear-cache:result' => [Command\ClearCache\ResultCommand::class, 'Clear cached query results.'],
    ];

    public function register(): void
    {
        $this->container->factory(EntityManagerInterface::class, static fn(ContainerInterface $container): EntityManagerInterface => self::entityManager($container), shared: true);
        $this->container->alias(EntityManager::class, EntityManagerInterface::class);
        $this->container->factory(
            EntityManagerProvider::class,
            static fn(ContainerInterface $container): EntityManagerProvider => new SingleManagerProvider($container->get(EntityManagerInterface::class)),
            shared: true,
        );
        $this->container->factory(EntitySchemaProvider::class, static fn(ContainerInterface $container): EntitySchemaProvider => new EntitySchemaProvider(
            $container->get(EntityManagerInterface::class),
            self::options($container)->connection,
        ), shared: true);
        $this->container->alias(SchemaProviderInterface::class, EntitySchemaProvider::class);
        $this->container->bind(ClearEntityManagerMiddleware::class);
        foreach (self::COMMANDS as [$class]) {
            $this->container->bind($class);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        foreach (self::COMMANDS as $name => [$class, $description]) {
            $commands->register($name, $class, $description);
        }
    }

    private static function entityManager(ContainerInterface $container): EntityManagerInterface
    {
        $options = self::options($container);
        $debug = (bool) $container->get(Configuration::class)->get('app.debug', false);
        // Without entity directories, map no classes rather than fail: the attribute driver needs a source.
        $paths = $options->entityPaths === [] ? new ClassNames([]) : $options->entityPaths;
        $config = ORMSetup::createAttributeMetadataConfig($paths, $debug, null, self::cache($container, $options, $debug));
        $config->enableNativeLazyObjects(true);
        if ($options->underscoreNaming) {
            $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        }

        return new EntityManager($container->get(ConnectionManager::class)->connection($options->connection), $config);
    }

    private static function cache(ContainerInterface $container, OrmOptions $options, bool $debug): CacheItemPoolInterface
    {
        if ($options->metadataCache === 'array') {
            return new ArrayAdapter();
        }
        if ($options->metadataCache === 'auto') {
            // A persistent cache would keep stale mapping while entities are being edited.
            return !$debug && $container->has(CacheItemPoolInterface::class) ? $container->get(CacheItemPoolInterface::class) : new ArrayAdapter();
        }
        $pool = $container->get($options->metadataCache);
        if (!$pool instanceof CacheItemPoolInterface) {
            throw new InvalidArgumentException("orm.metadata_cache \"{$options->metadataCache}\" must be a PSR-6 CacheItemPoolInterface service.");
        }

        return $pool;
    }

    private static function options(ContainerInterface $container): OrmOptions
    {
        $config = $container->get(Configuration::class)->get('orm', []);
        if (!is_array($config)) {
            throw new InvalidArgumentException('The orm configuration must be an array.');
        }

        return OrmOptions::from($config, $container->get(Application::class)->getRootDir());
    }
}
