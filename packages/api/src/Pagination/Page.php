<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use ArrayIterator;
use Countable;
use Crystoline\Xpsr\Api\Resource;
use Crystoline\Xpsr\Http\ResponsableInterface;
use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use IteratorAggregate;
use JsonSerializable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Traversable;

/**
 * One page of items and where it sits: as JSON, {"data": [...], "links": {...}, "meta": {...}}.
 * Returned from a route handler as it is, the items are sent as json_encode() renders them; a
 * resource collection built from the page shapes them.
 *
 * @implements IteratorAggregate<int, mixed>
 */
final class Page implements ResponsableInterface, JsonSerializable, Countable, IteratorAggregate
{
    /**
     * @param list<mixed> $items
     * @param int|null $total how many items there are in all; null when not counted (simplePaginate)
     * @param bool $hasMore whether a page follows this one
     */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly ?int $total,
        public readonly bool $hasMore,
        private readonly ?UriInterface $uri = null,
    ) {}

    public function lastPage(): ?int
    {
        return $this->total === null ? null : max(1, (int) ceil($this->total / $this->perPage));
    }

    /** The number of the first item on the page, counting from 1; null on an empty page. */
    public function from(): ?int
    {
        return $this->items === [] ? null : ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function to(): ?int
    {
        return $this->items === [] ? null : ($this->currentPage - 1) * $this->perPage + count($this->items);
    }

    /** The request's URL without its query string, or "" when the page was not made for a request. */
    public function path(): string
    {
        return $this->uri === null ? '' : (string) $this->uri->withQuery('')->withFragment('');
    }

    /** The URL of a page: the request's URL with "page" replaced and the other parameters kept. */
    public function url(int $page): string
    {
        $query = [];
        if ($this->uri !== null) {
            parse_str($this->uri->getQuery(), $query);
        }
        $query['page'] = $page;

        return $this->path() . '?' . http_build_query($query);
    }

    /** @return array{first: string, last: string|null, prev: string|null, next: string|null} */
    public function links(): array
    {
        $last = $this->lastPage();

        return [
            'first' => $this->url(1),
            'last' => $last === null ? null : $this->url($last),
            'prev' => $this->currentPage > 1 ? $this->url($this->currentPage - 1) : null,
            'next' => $this->hasMore ? $this->url($this->currentPage + 1) : null,
        ];
    }

    /** @return array<string, int|string|null> */
    public function meta(): array
    {
        $meta = [
            'current_page' => $this->currentPage,
            'from' => $this->from(),
            'to' => $this->to(),
            'per_page' => $this->perPage,
            'path' => $this->path(),
        ];
        if ($this->total !== null) {
            $meta['total'] = $this->total;
            $meta['last_page'] = $this->lastPage();
        }

        return $meta;
    }

    /** @return array{data: list<mixed>, links: array<string, string|null>, meta: array<string, int|string|null>} */
    public function toArray(): array
    {
        return ['data' => $this->items, 'links' => $this->links(), 'meta' => $this->meta()];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function toResponse(ServerRequestInterface $request, ContainerInterface $container): ResponseInterface
    {
        return $container->get(ResponseBuilder::class)->json(['data' => Resource::value($this->items, $request)] + $this->toArray());
    }
}
