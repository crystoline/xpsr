<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit;

use InvalidArgumentException;

/** What an entry is about, or who did it: a type, such as App\Model\Payment, and an ID. */
final class AuditKey
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
        if ($type === '' || strlen($type) > 255) {
            throw new InvalidArgumentException('An audit type must be 1 to 255 characters, such as App\Model\Payment.');
        }
        if ($id === '' || strlen($id) > 255) {
            throw new InvalidArgumentException('An audit ID must be 1 to 255 characters.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /** As "App\Model\Payment 42", for logs and errors. */
    public function __toString(): string
    {
        return "{$this->type} {$this->id}";
    }
}
