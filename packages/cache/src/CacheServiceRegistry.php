<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\Console\CacheClearCommand;
use Crystoline\Xpsr\Cache\RateLimit\ClientKeyResolverInterface;
use Crystoline\Xpsr\Cache\RateLimit\IpKeyResolver;
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;
use Crystoline\Xpsr\Cache\RateLimit\RateLimitMiddleware;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Registers one application cache, available as PSR-16 (Psr\SimpleCache\CacheInterface) and
 * PSR-6 (Psr\Cache\CacheItemPoolInterface) over the same storage, the cache:clear command, and a
 * rate-limit middleware service for each limit in config/rate_limit.php (RateLimit::named()).
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
        $this->container->singleton(IpKeyResolver::class);

        // One middleware service per configured rate limit: RateLimit::named($name).
        $limits = $this->container->get(Configuration::class)->get('rate_limit.limits', []);
        foreach (is_array($limits) ? array_keys($limits) : [] as $name) {
            if (!is_string($name)) {
                continue;
            }
            $this->container->factory(RateLimit::named($name), static fn(ContainerInterface $container): RateLimitMiddleware => self::rateLimit($container, $name), shared: true);
        }
    }

    private static function rateLimit(ContainerInterface $container, string $name): RateLimitMiddleware
    {
        $options = $container->get(Configuration::class)->get("rate_limit.limits.{$name}");
        if (!is_array($options)) {
            throw new InvalidArgumentException("rate_limit.limits.{$name} must be an array with \"limit\" and \"interval\".");
        }
        $unknown = array_diff(array_keys($options), ['limit', 'interval', 'key']);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown option rate_limit.limits.%s.%s. Known options: limit, interval, key.', $name, (string) reset($unknown)));
        }
        $limit = $options['limit'] ?? null;
        if (!is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException("rate_limit.limits.{$name}.limit must be a whole number of requests, 1 or more.");
        }
        $interval = $options['interval'] ?? null;
        if (!is_string($interval) || self::seconds($interval) < 1) {
            throw new InvalidArgumentException("rate_limit.limits.{$name}.interval must be a period such as \"1 minute\" or \"15 minutes\".");
        }
        $keyService = $options['key'] ?? IpKeyResolver::class;
        $keys = is_string($keyService) ? $container->get($keyService) : null;
        if (!$keys instanceof ClientKeyResolverInterface) {
            throw new InvalidArgumentException("rate_limit.limits.{$name}.key must name a service implementing " . ClientKeyResolverInterface::class . '.');
        }
        $factory = new RateLimiterFactory(
            ['id' => "xpsr_rate_limit_{$name}", 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new CacheStorage($container->get(CacheItemPoolInterface::class)),
        );

        return new RateLimitMiddleware($name, $factory, $keys);
    }

    /** The length of a period such as "1 minute" in seconds; 0 when it is not a period. */
    private static function seconds(string $interval): int
    {
        try {
            return (new DateTimeImmutable('@0'))->add(DateInterval::createFromDateString($interval))->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
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
