<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Channel;

use Closure;
use Crystoline\Xpsr\Notifications\ChannelInterface;
use Crystoline\Xpsr\Notifications\DatabaseNotificationInterface;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\Inbox;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * The "database" channel: DatabaseNotificationInterface's data, kept in the notifications table
 * for the Inbox.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class DatabaseChannel implements ChannelInterface
{
    /** @param Closure(): Inbox $inbox */
    public function __construct(private readonly Closure $inbox) {}

    public function supports(NotificationInterface $notification): bool
    {
        return $notification instanceof DatabaseNotificationInterface;
    }

    /** @return array{type: string, data: array<array-key, mixed>}|null */
    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): ?array
    {
        if (!$notification instanceof DatabaseNotificationInterface) {
            throw new LogicException($notification::class . ' does not implement DatabaseNotificationInterface.');
        }
        if ($to->notifiableKey() === null) {
            return null;
        }
        $data = $notification->toDatabase($to);
        try {
            json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException($notification::class . "::toDatabase() must return data JSON can encode: {$error->getMessage()}.", previous: $error);
        }
        $type = method_exists($notification, 'databaseType') ? $notification->databaseType() : $notification::class;
        if (!is_string($type) || $type === '' || strlen($type) > 255) {
            throw new InvalidArgumentException($notification::class . '::databaseType() must return 1 to 255 characters.');
        }

        return ['type' => $type, 'data' => $data];
    }

    public function deliver(Delivery $delivery): void
    {
        $message = $delivery->message;
        if (!is_array($message) || !is_string($message['type'] ?? null) || !is_array($message['data'] ?? null) || $delivery->key === null) {
            throw new LogicException('The database channel delivers [\'type\' => ..., \'data\' => [...]] to a recipient with a notifiable key.');
        }
        ($this->inbox)()->add($delivery->id, $message['type'], $delivery->key, $message['data']);
    }
}
