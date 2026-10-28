<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Registry;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Console\Commands\SessionPruneCommand;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\Session\ArraySessionStore;
use Crystoline\Xpsr\Session\CurrentSession;
use Crystoline\Xpsr\Session\FileSessionStore;
use Crystoline\Xpsr\Session\SessionManager;
use Crystoline\Xpsr\Session\SessionOptions;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * Registers sessions, configured in config/session.php:
 *
 *   Crystoline\Xpsr\Session\SessionStoreInterface   the store named by session.store
 *   Crystoline\Xpsr\Session\SessionManager          loads and saves sessions
 *   Crystoline\Xpsr\Session\SessionMiddleware       and CsrfMiddleware, for route groups
 *   Crystoline\Xpsr\Session\CurrentSession          the session of the request being handled
 *
 * and the session:prune command. The cache and database stores come from their packages, which
 * register them as "session.store.cache" and "session.store.database".
 */
final class SessionServiceRegistry extends ServiceRegistry
{
    /** Stores provided by other packages: [service ID, package, registry]. */
    public const STORES = [
        'cache' => ['session.store.cache', 'crystoline/xpsr-cache', 'Crystoline\Xpsr\Cache\CacheServiceRegistry'],
        'database' => ['session.store.database', 'crystoline/xpsr-database', 'Crystoline\Xpsr\Database\DatabaseServiceRegistry'],
    ];

    public function register(): void
    {
        $this->container->factory(SessionOptions::class, static function (ContainerInterface $container): SessionOptions {
            $config = $container->get(Configuration::class)->get('session', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The session configuration must be an array.');
            }

            return SessionOptions::fromArray($config, $container->get(Application::class)->getRootDir());
        }, shared: true);
        $this->container->singleton(CurrentSession::class);
        $this->container->factory(FileSessionStore::class, static function (ContainerInterface $container): FileSessionStore {
            $options = $container->get(SessionOptions::class);

            return new FileSessionStore($options->directory, $options->lifetime, $container->get(ClockInterface::class));
        }, shared: true);
        $this->container->factory(SessionStoreInterface::class, static function (ContainerInterface $container): SessionStoreInterface {
            $options = $container->get(SessionOptions::class);
            $name = $options->store;
            if ($name === 'file') {
                return $container->get(FileSessionStore::class);
            }
            if ($name === 'array') {
                return new ArraySessionStore($options->lifetime, $container->get(ClockInterface::class));
            }
            [$id, $package, $registry] = self::STORES[$name] ?? [$name, null, null];
            if (!$container->has($id)) {
                throw new InvalidArgumentException($package !== null
                    ? "session.store {$name} needs {$package}: run composer require {$package}, and add {$registry} to app.registry."
                    : 'session.store must be file, cache, database, array, or a service implementing ' . SessionStoreInterface::class . "; \"{$name}\" is none of these.");
            }
            $store = $container->get($id);
            if (!$store instanceof SessionStoreInterface) {
                throw new InvalidArgumentException("session.store \"{$name}\" is a service that does not implement " . SessionStoreInterface::class . '. Use file, cache, database, array, or a session store service.');
            }

            return $store;
        }, shared: true);
        $this->container->factory(SessionManager::class, static fn(ContainerInterface $container): SessionManager => new SessionManager(
            $container->get(SessionStoreInterface::class),
            $container->get(SessionOptions::class),
        ), shared: true);
        $this->container->bind(SessionPruneCommand::class);
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('session:prune', SessionPruneCommand::class, 'Remove expired sessions from the session store.');
        }
    }
}
