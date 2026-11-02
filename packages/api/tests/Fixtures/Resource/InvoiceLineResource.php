<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures\Resource;

use Crystoline\Xpsr\Api\Resource;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A line inside its invoice: without the invoice, since a Doctrine line always has its loaded
 * parent, and the two resources would render each other without end.
 */
final class InvoiceLineResource extends Resource
{
    public function toArray(ServerRequestInterface $request): array
    {
        return ['id' => $this->resource->id, 'amount' => $this->resource->amount];
    }
}
