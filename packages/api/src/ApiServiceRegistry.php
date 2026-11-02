<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

use Crystoline\Xpsr\Api\Console\MakeResourceCommand;
use Crystoline\Xpsr\Api\Pagination\ArrayPaginator;
use Crystoline\Xpsr\Api\Pagination\DbalPaginator;
use Crystoline\Xpsr\Api\Pagination\DoctrinePaginator;
use Crystoline\Xpsr\Api\Pagination\EloquentPaginator;
use Crystoline\Xpsr\Api\Pagination\Pagination;
use Crystoline\Xpsr\Api\Pagination\PaginatorInterface;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Registers the API services, configured in config/api.php (see ApiOptions):
 *
 *   Crystoline\Xpsr\Api\Pagination\Pagination  fromRequest() and page(), with the paginators for
 *                                               the installed packages. Shared.
 *   Crystoline\Xpsr\Api\ApiOptions              the configuration. Shared.
 *
 * and the make:resource command. Resources need no service: a route handler returns one, and
 * the framework turns it into the response with these options.
 */
final class ApiServiceRegistry extends ServiceRegistry
{
    public function register(): void
    {
        $this->container->factory(ApiOptions::class, static function (ContainerInterface $container): ApiOptions {
            $config = $container->get(Configuration::class)->get('api', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The api configuration must be an array.');
            }

            return ApiOptions::fromArray($config);
        }, shared: true);
        $this->container->factory(Pagination::class, static fn(ContainerInterface $container): Pagination => new Pagination(
            self::paginators(),
            $container->get(ApiOptions::class),
        ), shared: true);
        $this->container->bind(MakeResourceCommand::class);
    }

    public function boot(): void
    {
        if ($this->container->has(CommandRegistry::class)) {
            $this->container->get(CommandRegistry::class)->register('make:resource', MakeResourceCommand::class, 'Create an API resource class.');
        }
    }

    /**
     * The paginators for the installed packages, arrays last.
     *
     * @return list<PaginatorInterface>
     */
    public static function paginators(): array
    {
        $paginators = [];
        if (class_exists(\Doctrine\DBAL\Query\QueryBuilder::class)) {
            $paginators[] = new DbalPaginator();
        }
        if (class_exists(\Doctrine\ORM\Tools\Pagination\Paginator::class)) {
            $paginators[] = new DoctrinePaginator();
        }
        if (class_exists(\Illuminate\Database\Eloquent\Builder::class)) {
            $paginators[] = new EloquentPaginator();
        }
        $paginators[] = new ArrayPaginator();

        return $paginators;
    }
}
