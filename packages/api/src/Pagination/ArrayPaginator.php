<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

/** Pages an array (iterables are turned into arrays first). */
final class ArrayPaginator implements PaginatorInterface
{
    public function supports(mixed $query): bool
    {
        return is_array($query);
    }

    public function count(mixed $query): int
    {
        return is_array($query) ? count($query) : 0;
    }

    public function items(mixed $query, int $offset, int $limit): array
    {
        return is_array($query) ? array_slice(array_values($query), $offset, $limit) : [];
    }
}
