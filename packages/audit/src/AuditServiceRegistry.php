<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use Crystoline\Xpsr\Audit\Console\MakeAuditTableCommand;
use Crystoline\Xpsr\Audit\Console\PruneCommand;
use Crystoline\Xpsr\Audit\Doctrine\DoctrineAuditSubscriber;
use Crystoline\Xpsr\Audit\Eloquent\EloquentAuditing;
use Crystoline\Xpsr\Auth\CurrentIdentity;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Log\Monolog\RequestId;
use Crystoline\Xpsr\Orm\Doctrine\DoctrineListeners;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * Registers the audit log, configured in config/audit.php (see AuditOptions):
 *
 *   Crystoline\Xpsr\Audit\AuditLog                record(), for(), by(), recent(), search(),
 *                                                 query(), prune(). Shared.
 *   Crystoline\Xpsr\Audit\AuditContext            the source, IP, and user agent of the current
 *                                                 work. Shared.
 *   Crystoline\Xpsr\Audit\AuditContextMiddleware  for app.middleware.
 *   Crystoline\Xpsr\Audit\Keys                    the AuditKey of an object. Shared.
 *
 * the commands make:audit-table and audit:prune, the Eloquent Audited trait's recording, and
 * the Doctrine #[Audited] subscriber, each when its ORM package is installed. List it after the
 * database and ORM providers.
 */
final class AuditServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(AuditOptions::class, static function (ContainerInterface $container): AuditOptions {
            $config = $container->get(Configuration::class)->get('audit', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The audit configuration must be an array.');
            }

            return AuditOptions::fromArray($config);
        }, shared: true);
        $this->container->singleton(AuditContext::class);
        $this->container->factory(AuditContextMiddleware::class, static function (ContainerInterface $container): AuditContextMiddleware {
            $proxies = $container->get(Configuration::class)->get('app.trusted_proxies', []);

            return new AuditContextMiddleware($container->get(AuditContext::class), is_array($proxies) ? array_values(array_filter($proxies, 'is_string')) : []);
        });
        $this->container->factory(Keys::class, static fn(ContainerInterface $container): Keys => new Keys(
            self::hasDoctrine($container) ? static fn(): ?EntityManagerInterface => $container->get(EntityManagerInterface::class) : null,
        ), shared: true);
        $this->container->factory(AuditLog::class, static function (ContainerInterface $container): AuditLog {
            $options = $container->get(AuditOptions::class);
            $requestIds = class_exists(RequestId::class) && $container->has(RequestId::class) ? $container->get(RequestId::class) : null;

            return new AuditLog(
                $container->get(ConnectionManager::class)->connection($options->connection),
                $options,
                $container->get(Keys::class),
                $container->get(AuditContext::class),
                $container->get(ClockInterface::class),
                $container->has(CurrentIdentity::class) ? $container->get(CurrentIdentity::class) : null,
                $requestIds === null ? null : static fn(): ?string => $requestIds->get(),
            );
        }, shared: true);
        if (self::hasDoctrine($this->container)) {
            $this->container->factory(DoctrineAuditSubscriber::class, static fn(ContainerInterface $container): DoctrineAuditSubscriber => new DoctrineAuditSubscriber(
                $container->get(AuditLog::class),
                $container->get(AuditOptions::class),
            ), shared: true);
        }
        $this->container->bind(MakeAuditTableCommand::class);
        $this->container->bind(PruneCommand::class);
    }

    public function boot(): void
    {
        if (class_exists(Model::class)) {
            $container = $this->container;
            EloquentAuditing::use(static fn(): array => [$container->get(AuditLog::class), $container->get(AuditOptions::class)]);
        }
        if (self::hasDoctrine($this->container) && $this->container->has(DoctrineListeners::class)) {
            $this->container->get(DoctrineListeners::class)->add(DoctrineAuditSubscriber::class);
        }
        if ($this->container->has(CommandRegistry::class)) {
            $commands = $this->container->get(CommandRegistry::class);
            $commands->register('make:audit-table', MakeAuditTableCommand::class, 'Create the migration for the audit log table.');
            $commands->register('audit:prune', PruneCommand::class, 'Remove old audit log entries.');
        }
    }

    /** Whether crystoline/xpsr-orm-doctrine is installed and its provider listed; a function, so static analysis does not fold it. */
    private static function hasDoctrine(ContainerInterface $container): bool
    {
        return interface_exists(EntityManagerInterface::class) && $container->has(EntityManagerInterface::class);
    }
}
