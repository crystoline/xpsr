# crystoline/xpsr-api

JSON responses for XPSR APIs. A **resource** shapes an object into the JSON a client gets, field by field, with fields that appear only under a condition or when a relation was loaded. **Pagination** reads the page a request asks for and fetches it from a DBAL, Doctrine, or Eloquent query, with links and totals in the response. The output has the shape Laravel clients know (`data`, `links`, `meta`), so an API moved from Laravel keeps its clients.

```sh
composer require crystoline/xpsr-api
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Api\ApiServiceRegistry::class,
```

Nothing else is needed. `config/api.php` is optional:

```php
return [
    'wrap' => 'data',       // the key the data goes under; null for a bare body
    'per_page' => 15,       // items per page when the request does not ask for a number
    'max_per_page' => 100,  // the most a request may ask for with per_page
];
```

## Resources

`php xpsr make:resource PaymentResource` writes `app/Http/Resource/PaymentResource.php`. A resource takes the object (or array) it shapes and lists the fields in `toArray()`:

```php
use Crystoline\Xpsr\Api\Resource;
use Psr\Http\Message\ServerRequestInterface;

final class PaymentResource extends Resource
{
    public function toArray(ServerRequestInterface $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'fee' => $this->when($request->getAttribute('merchant') === true, $payment->fee),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'created_at' => $payment->created_at->format(DATE_ATOM),
        ];
    }
}
```

A route handler returns the resource, and the framework turns it into the response:

```php
public function show(ServerRequestInterface $request): PaymentResource
{
    return new PaymentResource($this->payments->find($request->getAttribute('id')));
}

public function store(ServerRequestInterface $request): PaymentResource
{
    $payment = $this->payments->create(PaymentInput::from($request));

    return new PaymentResource($payment)->withStatus(201)->withHeaders(['Location' => "/payments/{$payment->id}"]);
}
```

The response is `application/json` with the fields under `data`:

```json
{"data": {"id": 7, "reference": "PAY-7", "amount": 125000, "currency": "NGN", "created_at": "2026-11-01T10:00:00+00:00"}}
```

`withStatus()`, `withHeaders()`, and `withMeta()` set the status, add headers, and add a top-level `meta` next to the data. `wrap('payment')` changes the key for this response, and `wrap(null)` sends the fields bare; `api.wrap` sets the default for every response. A resource of `null`, such as `new CustomerResource(null)`, renders as `null`.

### Fields

`toArray()` returns anything `json_encode()` takes, plus:

| Field | Output |
|---|---|
| `$this->when($condition, $value)` | `$value` when the condition holds; otherwise the field is left out. A `Closure` value is called only then. `when($condition, $value, $default)` gives `$default` instead of leaving the field out. |
| `$this->whenNotNull($value)` | `$value` unless it is `null`; then the field is left out. |
| `$this->mergeWhen($condition, [...])` | Several fields at once, spread into the array that holds them. |
| `$this->whenLoaded('customer')` | The relation when it was loaded; otherwise the field is left out, so a resource never runs a query of its own. See below. |
| `new CustomerResource($value)` | A nested resource: its fields, without an envelope. |
| `RefundResource::collection($items)` | A nested list. |
| A `Page` | Its `data`, `links`, and `meta`. |

A field that is left out disappears with its key; in a list, the remaining items close up. The condition of `when()` and `mergeWhen()` can be a `Closure` too.

### Reading the subject

`$this->resource` is the object given to the resource. `$this->field` reads a field of it as well, so resources written for Laravel carry over: for an object it reads the property (or an Eloquent attribute), for an array the key. Static analysis does not know those fields; tell it with `@property` annotations on the resource class, or read `$this->resource->field`.

### Relations

`whenLoaded('customer')` includes a relation only when it was already loaded, so listing a hundred payments with `->with('customer')` runs two queries, and forgetting the `with()` leaves the field out instead of running a hundred queries.

- **Eloquent** models report loaded relations themselves (`relationLoaded()`), as in Laravel.
- **Doctrine** entities and other objects: a relation is loaded when its property is set and is neither an uninitialized lazy object (a to-one relation that was not fetched) nor an unloaded `PersistentCollection` (a to-many that was not touched). A fetch join, `initialize()`, or any use of the collection loads it.

`whenLoaded('customer', fn($customer) => $customer->name)` maps the loaded relation; with a plain value in place of the closure, that value is used. A relation that is loaded but `null` renders as `null`.

With Doctrine, both sides of a relation are set once one side is loaded: a line fetched with its invoice has the invoice, and the invoice has its lines. A resource that includes its parent, used from a resource that includes its children, would render without end. Use a different resource for the nested side, such as an `InvoiceLineResource` without the invoice.

## Collections

`PaymentResource::collection($payments)` renders one resource per item, from any iterable or a `Page`:

```php
public function index(ServerRequestInterface $request): ResourceCollection
{
    $page = $this->pagination->fromRequest($request)->paginate(Payment::query()->with('customer')->latest());

    return PaymentResource::collection($page)->withMeta(['currency' => 'NGN']);
}
```

```json
{
  "data": [{"id": 7, "...": "..."}, {"id": 6, "...": "..."}],
  "links": {"first": "https://api.example.com/payments?page=1", "last": "https://api.example.com/payments?page=12", "prev": null, "next": "https://api.example.com/payments?page=2"},
  "meta": {"current_page": 1, "from": 1, "to": 15, "per_page": 15, "path": "https://api.example.com/payments", "total": 180, "last_page": 12, "currency": "NGN"}
}
```

A collection of a plain list is wrapped like a resource (`{"data": [...]}`), and `wrap(null)` or `api.wrap` `null` sends the bare list; a collection of a page is always wrapped, because its links and meta need the envelope. A collection class of its own can shape the whole body:

```php
use Crystoline\Xpsr\Api\ResourceCollection;

final class PaymentCollection extends ResourceCollection
{
    protected ?string $collects = PaymentResource::class;

    public function toArray(ServerRequestInterface $request): array
    {
        return ['payments' => $this->items(), 'count' => count($this->items())];
    }
}
```

An associative array from `toArray()` is the body as it is; a list is wrapped.

## Pagination

`Crystoline\Xpsr\Api\Pagination\Pagination` is a service. `fromRequest($request)` reads `page` (from 1) and `per_page` (up to `api.max_per_page`; more is cut down to it) from the query string; anything else, such as a word or `0`, means the default. `fromRequest($request, perPage: 50, max: 500)` changes the defaults for one route.

```php
$page = $this->pagination->fromRequest($request)->paginate($query);
```

| Query | Paginated by |
|---|---|
| A DBAL `QueryBuilder` | `crystoline/xpsr-database`. The total replaces the selected columns with `COUNT(*)`, so a query with `GROUP BY` or `DISTINCT` needs its own count: `paginate($query, count: fn() => ...)`. |
| A Doctrine `Query` or `QueryBuilder` | `crystoline/xpsr-orm-doctrine`, with Doctrine's `Paginator`: a query that fetch-joins a collection counts and limits entities, not joined rows. |
| An Eloquent builder, a relation, or Illuminate's query builder | `crystoline/xpsr-orm-eloquent`, without `illuminate/pagination`. |
| An array or any iterable | Always. |

`paginate()` counts the total, so the response has `total` and `last_page`. `simplePaginate()` fetches one item more than the page instead, to know whether there is a next page; `total`, `last_page`, and the `last` link are then `null`. Links keep the request's other query parameters and replace `page`; `path` is the request's URL without the query string.

The `Page` returned has `items`, `currentPage`, `perPage`, `total`, `hasMore`, `links()`, and `meta()`; it counts and iterates as the items. Returned from a route handler as it is, the items are sent as `json_encode()` renders them, which suits arrays and Eloquent models. `Pagination::page(2, 50)` makes a page request in code, for a command or a test; its links are relative.

Another kind of query gets a `Crystoline\Xpsr\Api\Pagination\PaginatorInterface` (`supports()`, `count()`, `items()`), and the application binds `Pagination` with its paginators: `new Pagination([new MyPaginator(), ...ApiServiceRegistry::paginators()], $options)`.

## Testing

Requests in tests come back with the JSON assertions of [docs/TESTING.md](../../docs/TESTING.md):

```php
$this->getJson('/payments/7')
    ->assertOk()
    ->assertJsonPath('data.reference', 'PAY-7')
    ->assertJsonMissingPath('data.fee');

$this->getJson('/payments?page=2')
    ->assertJsonCount(15, 'data')
    ->assertJsonPath('meta.current_page', 2);
```

A resource renders without a request cycle too: `new PaymentResource($payment)->resolve($request)` gives the fields, and `->output($request)` the body with the envelope.

## Coming from Laravel

| Laravel | XPSR |
|---|---|
| `JsonResource` | `Crystoline\Xpsr\Api\Resource`. `toArray($request)` is the same, with a PSR-7 request. |
| `return new PaymentResource($payment);` | The same; `PaymentResource::make()` is `new PaymentResource()`. |
| `->response()->setStatusCode(201)` | `->withStatus(201)`, and `->withHeaders()` for headers. |
| `->additional(['meta' => [...]])` | `->withMeta([...])`. |
| `JsonResource::withoutWrapping()` | `api.wrap` `null`, or `->wrap(null)` on one response. `$wrap` on a class is `->wrap('payment')`. |
| `$this->when()`, `whenNotNull()`, `mergeWhen()`, `whenLoaded()` | The same. `whenHas()`, `whenCounted()`, `whenPivotLoaded()`, and `whenAppended()` are not provided. |
| `$this->id` | The same, or `$this->resource->id`. |
| `PaymentResource::collection($payments)` | The same. The anonymous collection is `ResourceCollection`; a collection class sets `$collects` as before. |
| `$payments->paginate()` | `$this->pagination->fromRequest($request)->paginate(Payment::query())`, the Laravel shape of `data`, `links`, and `meta`, without the `links` array of page numbers inside `meta`. Cursor pagination is not provided. |
| `preserveKeys` | Not provided: lists are lists. |

Resources render with the request of the route handler that returned them, so one resource can be rendered only within a request. There is no `JsonResource::make()->toArray()` without a request; pass the request you have.
