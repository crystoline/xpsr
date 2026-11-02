<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Api\Tests\Fixtures;

use Crystoline\Xpsr\Api\Resource;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads its fields through $this->field, as Laravel resources do; the annotations tell static
 * analysis what the subject has.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 */
final class CustomerResource extends Resource
{
    public function toArray(ServerRequestInterface $request): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
