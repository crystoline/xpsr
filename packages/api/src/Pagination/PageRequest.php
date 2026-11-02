<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use Closure;
use Psr\Http\Message\UriInterface;
use Traversable;

/** The page a request asks for. paginate() and simplePaginate() fetch it from a query. */
final class PageRequest
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        private readonly Pagination $pagination,
        private readonly ?UriInterface $uri = null,
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The page, with the total: the items of the page and how many there are in all, so the
     * response has "total" and "last_page". $count replaces the paginator's own count for queries
     * it cannot count, such as a DBAL query with GROUP BY.
     *
     * @param (Closure(): int)|null $count
     */
    public function paginate(mixed $query, ?Closure $count = null): Page
    {
        $query = self::materialize($query);
        $paginator = $this->pagination->paginator($query);
        $total = $count !== null ? $count() : $paginator->count($query);
        $items = $total > $this->offset() ? $paginator->items($query, $this->offset(), $this->perPage) : [];

        return new Page($items, $this->page, $this->perPage, $total, $this->page * $this->perPage < $total, $this->uri);
    }

    /**
     * The page without a total: one extra item is fetched to learn whether there is a next page,
     * which saves the count query on large tables.
     */
    public function simplePaginate(mixed $query): Page
    {
        $query = self::materialize($query);
        $items = $this->pagination->paginator($query)->items($query, $this->offset(), $this->perPage + 1);

        return new Page(array_slice($items, 0, $this->perPage), $this->page, $this->perPage, null, count($items) > $this->perPage, $this->uri);
    }

    private static function materialize(mixed $query): mixed
    {
        return $query instanceof Traversable ? iterator_to_array($query, false) : $query;
    }
}
