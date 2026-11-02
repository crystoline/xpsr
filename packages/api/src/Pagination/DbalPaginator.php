<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Pages a DBAL query builder. The total replaces the selected columns with COUNT(*), so a query
 * with GROUP BY or DISTINCT needs its own count: PageRequest::paginate($query, count: fn() => ...).
 */
final class DbalPaginator implements PaginatorInterface
{
    public function supports(mixed $query): bool
    {
        return $query instanceof QueryBuilder;
    }

    public function count(mixed $query): int
    {
        return (int) (clone self::builder($query))->select('COUNT(*)')->resetOrderBy()->setFirstResult(0)->setMaxResults(null)->fetchOne();
    }

    public function items(mixed $query, int $offset, int $limit): array
    {
        return array_values((clone self::builder($query))->setFirstResult($offset)->setMaxResults($limit)->fetchAllAssociative());
    }

    private static function builder(mixed $query): QueryBuilder
    {
        return $query instanceof QueryBuilder ? $query : throw new InvalidArgumentException('Not a DBAL query builder: ' . get_debug_type($query) . '.');
    }
}
