<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use InvalidArgumentException;

/**
 * Pages a Doctrine query or query builder with Doctrine's Paginator, so a query that fetch-joins
 * a collection counts and limits root entities, not joined rows.
 */
final class DoctrinePaginator implements PaginatorInterface
{
    public function supports(mixed $query): bool
    {
        return $query instanceof Query || $query instanceof QueryBuilder;
    }

    public function count(mixed $query): int
    {
        return (new Paginator(self::query($query), fetchJoinCollection: true))->count();
    }

    public function items(mixed $query, int $offset, int $limit): array
    {
        $page = self::query($query)->setFirstResult($offset)->setMaxResults($limit);

        return iterator_to_array((new Paginator($page, fetchJoinCollection: true))->getIterator(), false);
    }

    /** A query of its own, so the caller's is not changed. */
    private static function query(mixed $query): Query
    {
        return match (true) {
            $query instanceof QueryBuilder => $query->getQuery(),
            $query instanceof Query => clone $query,
            default => throw new InvalidArgumentException('Not a Doctrine query: ' . get_debug_type($query) . '.'),
        };
    }
}
