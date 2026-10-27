<?php

declare(strict_types=1);

namespace Crystoline\Cache;

use Crystoline\Application;
use Crystoline\Cache\Console\CacheClearCommand;
use Crystoline\Configuration;
use Crystoline\Console\CommandRegistry;
use Crystoline\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Registers one application cache, available as PSR-16 (Psr\SimpleCache\CacheInterface) and
 * PSR-6 (Psr\Cache\CacheItemPoolInterface) over the same storage, and the cache:clear command.
 *
 * Configuration (config/cache.php, all optional):
 *
 *   driver       file (default), array, null, or service
 *   path         file driver directory, relative to the application root (default var/cache/app)
 *   namespace    key prefix, so several applications can share storage (default "app")
 *   default_ttl  lifetime in seconds for items stored without one (default 0: no expiry)
 *   service      service ID of a PSR-6 pool to use with the "service" driver, for example a
 *                Symfony RedisAdapter bound in config/factory.php
 */
final class CacheServiceRegistry extends ServiceRegistry
{
    public const DRIVERS = ['file', 'array', 'null', 'service'];

    public function register(): void
    {
        $this->container->factory(
            CacheItemPoolInterface::class,
            static fn(ContainerInterface $container): CacheItemPoolInterface => self::createPool($container),
            shared: true,
        );
        $this->container->factory(
            CacheInterface::class,
            static fn(ContainerInterface $container): CacheInterface => new Psr16Cache($container->get(CacheItemPoolInterface::class)),
            shared: true,
        );
        $this->container->bind(CacheClearCommand::class);
    }

    public function boot(): void
    {
        // The command is only offered when the console is part of the application.
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('cache:clear', CacheClearCommand::class, 'Remove every item from the application cache.');
        }
    }

    /** A description of the configured cache, for messages: "file cache in var/cache/app". */
    public static function describe(Configuration $config): string
    {
        $driver = $config->get('cache.driver', 'file');

        return match ($driver) {
            'file' => 'file cache in ' . $config->get('cache.path', 'var/cache/app'),
            'service' => 'cache service ' . $config->get('cache.service'),
            default => "{$driver} cache",
        };
    }

    private static function createPool(ContainerInterface $container): CacheItemPoolInterface
    {
        $config = $container->get(Configuration::class);
        $driver = $config->get('cache.driver', 'file');
        $namespace = $config->get('cache.namespace', 'app');
        $ttl = $config->get('cache.default_ttl', 0);
        if (!is_string($namespace) || !preg_match('/\A[\w.-]*\z/', $namespace)) {
            throw new InvalidArgumentException('cache.namespace may contain only letters, digits, "_", ".", and "-".');
        }
        if (!is_int($ttl) || $ttl < 0) {
            throw new InvalidArgumentException('cache.default_ttl must be a number of seconds (0 for no expiry).');
        }

        return match ($driver) {
            'file' => new FilesystemAdapter($namespace, $ttl, self::directory($container, $config)),
            'array' => new ArrayAdapter($ttl),
            'null' => new NullAdapter(),
            'service' => self::servicePool($container, $config),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown cache.driver "%s". Use %s.',
                is_scalar($driver) ? (string) $driver : get_debug_type($driver),
                implode(', ', self::DRIVERS),
            )),
        };
    }

    private static function directory(ContainerInterface $container, Configuration $config): string
    {
        $path = $config->get('cache.path', 'var/cache/app');
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('cache.path must be a directory path.');
        }
        $absolute = str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[/\\\\]#', $path);

        return $absolute ? $path : $container->get(Application::class)->getRootDir() . '/' . $path;
    }

    private static function servicePool(ContainerInterface $container, Configuration $config): CacheItemPoolInterface
    {
        $service = $config->get('cache.service');
        if (!is_string($service) || $service === '' || $service === CacheItemPoolInterface::class) {
            throw new InvalidArgumentException('The "service" cache driver needs cache.service: the ID of a PSR-6 pool service.');
        }
        $pool = $container->get($service);
        if (!$pool instanceof CacheItemPoolInterface) {
            throw new InvalidArgumentException("cache.service \"{$service}\" must be a PSR-6 CacheItemPoolInterface.");
        }
        return $pool;
    }
}
