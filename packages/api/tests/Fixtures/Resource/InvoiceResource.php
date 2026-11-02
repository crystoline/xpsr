<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Resource;

use Crystoline\Xpsr\Api\Resource;
use Psr\Http\Message\ServerRequestInterface;

/** Works on the Doctrine entity and the Eloquent model alike. */
final class InvoiceResource extends Resource
{
    public function toArray(ServerRequestInterface $request): array
    {
        return [
            'id' => $this->resource->id,
            'number' => $this->resource->number,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
