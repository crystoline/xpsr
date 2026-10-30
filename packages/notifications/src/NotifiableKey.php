<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use InvalidArgumentException;

/**
 * Who a recipient is in the notifications and push_subscriptions tables: a type, such as
 * App\Models\User, and an ID. These are Laravel's notifiable_type and notifiable_id (and the web
 * push package's subscribable_type and subscribable_id), so an application that moves from Laravel
 * keeps its stored notifications and push subscriptions by using the same type, the model's class
 * or its morph map alias.
 */
final class NotifiableKey
{
    public function __construct(
        public readonly string $type,
        public readonly string|int $id,
    ) {
        if ($type === '' || strlen($type) > 255) {
            throw new InvalidArgumentException('A notifiable type must be 1 to 255 characters, such as App\Models\User.');
        }
        if ($id === '' || strlen((string) $id) > 255) {
            throw new InvalidArgumentException('A notifiable ID must be 1 to 255 characters.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && (string) $this->id === (string) $other->id;
    }

    /** As "App\Models\User 42", for logs and errors. */
    public function __toString(): string
    {
        return "{$this->type} {$this->id}";
    }
}
