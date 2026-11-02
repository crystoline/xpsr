<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Pagination;

use Crystoline\Xpsr\Api\ApiOptions;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Reads which page a request asks for, then pages a query:
 *
 *   $page = $this->pagination->fromRequest($request)->paginate(Payment::query()->latest());
 *   return PaymentResource::collection($page);
 *
 * The request's "page" (from 1) and "per_page" (up to api.max_per_page) query parameters are
 * read; anything else, such as a word or 0, means the default. A service, built by
 * ApiServiceRegistry with the paginators for the installed packages.
 */
final class Pagination
{
    /** @param list<PaginatorInterface> $paginators tried in order; the first that supports a query pages it */
    public function __construct(private readonly array $paginators, private readonly ApiOptions $options = new ApiOptions()) {}

    /**
     * @param int|null $perPage items per page when the request does not say (default: api.per_page)
     * @param int|null $max the most the request may ask for (default: api.max_per_page)
     */
    public function fromRequest(ServerRequestInterface $request, ?int $perPage = null, ?int $max = null): PageRequest
    {
        $query = $request->getQueryParams();
        $max ??= $this->options->maxPerPage;
        $size = self::positive($query['per_page'] ?? null) ?? $perPage ?? $this->options->perPage;

        return new PageRequest(self::positive($query['page'] ?? null) ?? 1, min($size, $max), $this, $request->getUri());
    }

    /** A page chosen in code, such as in a command; links are relative when there is no URI. */
    public function page(int $page, ?int $perPage = null, ?UriInterface $uri = null): PageRequest
    {
        if ($page < 1 || ($perPage !== null && $perPage < 1)) {
            throw new InvalidArgumentException('The page and the items per page start at 1.');
        }

        return new PageRequest($page, $perPage ?? $this->options->perPage, $this, $uri);
    }

    /** @internal */
    public function paginator(mixed $query): PaginatorInterface
    {
        foreach ($this->paginators as $paginator) {
            if ($paginator->supports($query)) {
                return $paginator;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Cannot paginate %s: no paginator supports it. Built in: arrays and iterables, DBAL query builders, Doctrine queries, and Eloquent builders, each when its package is installed.',
            get_debug_type($query),
        ));
    }

    private static function positive(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) ? (int) $value : null;
    }
}
