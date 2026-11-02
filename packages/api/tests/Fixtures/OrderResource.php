<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

use Crystoline\Xpsr\Api\Resource;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Every kind of field: plain, conditional, merged, a nested resource, a nested collection, and
 * relations that may not be loaded. Reads the subject through $this->resource.
 *
 * @property Order $resource
 */
final class OrderResource extends Resource
{
    public function toArray(ServerRequestInterface $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'amount' => $this->when($request->getAttribute('admin') === true, $order->amount),
            'computed' => $this->when(true, fn(): string => 'called'),
            'note' => $this->whenNotNull($order->note),
            'paid' => $this->mergeWhen($order->paid, ['paid' => true, 'paid_at' => '2026-11-01']),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_name' => $this->whenLoaded('customer', fn(Customer $customer): string => strtoupper($customer->name)),
            'related' => self::collection($this->whenLoaded('related')),
            'tags' => ['a', $this->when(false, 'b'), 'c'],
        ];
    }
}
