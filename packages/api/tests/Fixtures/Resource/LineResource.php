<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Resource;

use Crystoline\Xpsr\Api\Resource;
use Psr\Http\Message\ServerRequestInterface;

final class LineResource extends Resource
{
    public function toArray(ServerRequestInterface $request): array
    {
        return [
            'id' => $this->resource->id,
            'amount' => $this->resource->amount,
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
        ];
    }
}
