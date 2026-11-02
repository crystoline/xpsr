<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

/**
 * Pages one kind of query. The built-in paginators cover arrays, DBAL query builders, Doctrine
 * queries, and Eloquent builders; an application can add its own by giving Pagination a list.
 */
interface PaginatorInterface
{
    public function supports(mixed $query): bool;

    /** How many items the query has in all. */
    public function count(mixed $query): int;

    /**
     * The items from $offset, at most $limit of them.
     *
     * @return list<mixed>
     */
    public function items(mixed $query, int $offset, int $limit): array;
}
