<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use Cycle\ORM\Select;
use InvalidArgumentException;

/** Pages a Cycle ORM Select: $repository->select()->where(...)->orderBy(...). */
final class CyclePaginator implements PaginatorInterface
{
    public function supports(mixed $query): bool
    {
        return $query instanceof Select;
    }

    public function count(mixed $query): int
    {
        return (clone self::select($query))->count();
    }

    public function items(mixed $query, int $offset, int $limit): array
    {
        return [...(clone self::select($query))->offset($offset)->limit($limit)->fetchAll()];
    }

    private static function select(mixed $query): Select
    {
        return $query instanceof Select ? $query : throw new InvalidArgumentException('Not a Cycle select: ' . get_debug_type($query) . '.');
    }
}
