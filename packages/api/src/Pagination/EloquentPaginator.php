<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/** Pages an Eloquent builder, a relation, or Illuminate's query builder, without illuminate/pagination. */
final class EloquentPaginator implements PaginatorInterface
{
    public function supports(mixed $query): bool
    {
        return $query instanceof EloquentBuilder || $query instanceof QueryBuilder || $query instanceof Relation;
    }

    public function count(mixed $query): int
    {
        $builder = self::builder($query);

        return (int) (clone ($builder instanceof EloquentBuilder ? $builder->toBase() : $builder))->getCountForPagination();
    }

    public function items(mixed $query, int $offset, int $limit): array
    {
        return array_values((clone self::builder($query))->skip($offset)->take($limit)->get()->all());
    }

    private static function builder(mixed $query): EloquentBuilder|QueryBuilder
    {
        return match (true) {
            $query instanceof Relation => $query->getQuery(),
            $query instanceof EloquentBuilder, $query instanceof QueryBuilder => $query,
            default => throw new InvalidArgumentException('Not an Eloquent query: ' . get_debug_type($query) . '.'),
        };
    }
}
