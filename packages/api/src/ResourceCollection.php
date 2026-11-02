<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api;

use Crystoline\Xpsr\Api\Pagination\Page;
use Crystoline\Xpsr\Http\ResponsableInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A list of resources, one per item, from Resource::collection($items) or a subclass:
 *
 *   final class PaymentCollection extends ResourceCollection
 *   {
 *       protected ?string $collects = PaymentResource::class;
 *   }
 *
 * The output is the list under the envelope key. A subclass's toArray() may return an
 * associative array instead, such as ['data' => $this->items(), 'total' => ...], which is then
 * the whole body. Built from a Page, the collection adds the page's links and meta, always under
 * an envelope.
 */
class ResourceCollection implements ResponsableInterface
{
    use RespondsWithJson;

    public readonly ?Page $page;
    /** @var iterable<mixed> */
    private readonly iterable $source;
    private readonly bool $missing;
    /** @var class-string<Resource>|null The resource each item is wrapped in; null leaves items as they are. */
    protected ?string $collects = null;

    /**
     * @param iterable<mixed>|Page|MissingValue $items
     * @param class-string<Resource>|null $collects
     */
    public function __construct(iterable|Page|MissingValue $items, ?string $collects = null)
    {
        $this->page = $items instanceof Page ? $items : null;
        $this->missing = $items instanceof MissingValue;
        $this->source = match (true) {
            $items instanceof Page => $items->items,
            $items instanceof MissingValue => [],
            default => $items,
        };
        $this->collects = $collects ?? $this->collects;
    }

    /**
     * The items, each wrapped in the resource class.
     *
     * @return list<mixed>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->source as $item) {
            $items[] = $this->collects === null ? $item : new $this->collects($item);
        }

        return $items;
    }

    /**
     * The output: the list of items, or an associative array for a whole body.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(ServerRequestInterface $request): array
    {
        return $this->items();
    }

    /** Whether it was built from whenLoaded() on a relation that was not loaded. */
    public function isMissing(): bool
    {
        return $this->missing;
    }

    /**
     * The output, rendered.
     *
     * @return array<array-key, mixed>
     */
    public function resolve(ServerRequestInterface $request): array
    {
        $resolved = Resource::value($this->toArray($request), $request);

        return is_array($resolved) ? $resolved : [];
    }

    public function output(ServerRequestInterface $request, ?string $wrap = 'data'): array
    {
        $data = $this->resolve($request);
        $body = $data;
        if (array_is_list($data)) {
            $key = $wrap ?? ($this->page !== null || $this->meta !== [] ? 'data' : null);
            $body = $key === null ? $data : [$key => $data];
        }
        if ($this->page !== null) {
            $body['links'] = $this->page->links();
            $body['meta'] = $this->page->meta();
        }

        return $this->withMetaIn($body);
    }
}
