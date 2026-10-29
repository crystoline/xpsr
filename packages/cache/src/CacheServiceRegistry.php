<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Cache;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Cache\Console\CacheClearCommand;
use Crystoline\Xpsr\Cache\RateLimit\ClientKeyResolverInterface;
use Crystoline\Xpsr\Cache\RateLimit\IpKeyResolver;
use Crystoline\Xpsr\Cache\RateLimit\LockedLimiterFactory;
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;
use Crystoline\Xpsr\Cache\RateLimit\RateLimitMiddleware;
use Crystoline\Xpsr\Cache\RateLimit\RateLimits;
use Crystoline\Xpsr\Cache\Session\CacheSessionStore;
use Crystoline\Xpsr\Session\SessionOptions;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Lock\Locks;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Closure;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Registers one application cache, available as PSR-16 (Psr\SimpleCache\CacheInterface) and
 * PSR-6 (Psr\Cache\CacheItemPoolInterface) over the same storage, the cache:clear command, the
 * limits in config/rate_limit.php (RateLimits from code, and a middleware service for each,
 * RateLimit::named()), and the session store for session.store "cache".
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

    /** Seconds a rate-limit lock lasts if its holder dies before releasing it. */
    private const LOCK_TTL = 5.0;

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
        // session.store "cache", resolved by the framework's SessionServiceRegistry.
        $this->container->factory(
            'session.store.cache',
            static fn(ContainerInterface $container): CacheSessionStore => new CacheSessionStore(
                $container->get(CacheInterface::class),
                $container->get(SessionOptions::class)->lifetime,
                $container->get(ClockInterface::class),
            ),
            shared: true,
        );

        $this->container->factory(RateLimits::class, static function (ContainerInterface $container): RateLimits {
            $storage = new CacheStorage($container->get(CacheItemPoolInterface::class));
            $locks = self::rateLimitLocks($container);

            return new RateLimits(static fn(string $name): RateLimiterFactoryInterface => self::limiter($container, $name, $storage, $locks));
        }, shared: true);

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
        $rateLimits = $container->get(RateLimits::class);
        if (!$rateLimits instanceof RateLimits) {
            throw new LogicException('The ' . RateLimits::class . ' service must be a RateLimits.');
        }
        $limiter = $rateLimits->limiter($name);
        $keyService = $container->get(Configuration::class)->get("rate_limit.limits.{$name}.key", IpKeyResolver::class);
        $keys = is_string($keyService) ? $container->get($keyService) : null;
        if (!$keys instanceof ClientKeyResolverInterface) {
            throw new InvalidArgumentException("rate_limit.limits.{$name}.key must name a service implementing " . ClientKeyResolverInterface::class . '.');
        }

        return new RateLimitMiddleware($name, $limiter, $keys);
    }

    /** @param (Closure(string): LockInterface)|null $locks */
    private static function limiter(ContainerInterface $container, string $name, CacheStorage $storage, ?Closure $locks): RateLimiterFactoryInterface
    {
        RateLimit::named($name); // checks the name
        $options = $container->get(Configuration::class)->get("rate_limit.limits.{$name}");
        if ($options === null) {
            throw new InvalidArgumentException("No rate limit is named \"{$name}\". Add it to rate_limit.limits in config/rate_limit.php.");
        }
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
        $seconds = is_string($interval) ? self::seconds($interval) : 0;
        if ($seconds < 1) {
            throw new InvalidArgumentException("rate_limit.limits.{$name}.interval must be a period such as \"1 minute\" or \"15 minutes\".");
        }

        return new LockedLimiterFactory("xpsr_rate_limit_{$name}", $limit, new DateInterval("PT{$seconds}S"), $storage, $locks);
    }

    /**
     * The locks that keep parallel requests from both taking a key's last unit: the
     * application's (crystoline/xpsr-lock) when its registry is listed, or else files in
     * var/locks, which only one server sees. The array and null caches keep no counts that
     * another process could change, so they need none.
     *
     * @return (Closure(string): LockInterface)|null a lock for a limiter ID
     */
    private static function rateLimitLocks(ContainerInterface $container): ?Closure
    {
        $driver = $container->get(Configuration::class)->get('cache.driver', 'file');
        if ($driver === 'array' || $driver === 'null') {
            return null;
        }
        // The store is checked because has() is true for any class, Locks too.
        if (class_exists(Locks::class) && $container->has(PersistingStoreInterface::class)) {
            $locks = $container->get(Locks::class);
            if (!$locks instanceof Locks) {
                throw new LogicException('The ' . Locks::class . ' service must be a Locks.');
            }
            $files = $container->get(PersistingStoreInterface::class) instanceof FlockStore;

            return static fn(string $id): LockInterface => $locks->lock($files ? self::sharedLock($id) : $id, self::LOCK_TTL);
        }
        $directory = $container->get(Application::class)->getRootDir() . '/var/locks';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new LogicException("Rate limits keep their locks in {$directory}, which could not be created.");
        }
        $factory = new LockFactory(new FlockStore($directory));

        return static fn(string $id): LockInterface => $factory->createLock(self::sharedLock($id), self::LOCK_TTL);
    }

    /**
     * One of 256 locks, for file locks: each key's file would stay behind after its lock is
     * released, one per client address. A lock is held for the milliseconds a count takes, so
     * keys that share one rarely wait for each other.
     */
    private static function sharedLock(string $id): string
    {
        return 'xpsr_rate_limit.' . (crc32($id) % 256);
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
