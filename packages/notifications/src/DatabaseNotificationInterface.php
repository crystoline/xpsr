<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * A notification kept in the database, on the "database" channel, for an in-app inbox (Inbox).
 * Needs crystoline/xpsr-database, and a recipient with a notifiable key.
 *
 * Its row's type is the notification's class, or what an optional method returns:
 *
 *     public function databaseType(): string   // such as 'payment-received'
 */
interface DatabaseNotificationInterface extends NotificationInterface
{
    /**
     * The data to keep, as JSON, such as ['reference' => ..., 'amount' => ...].
     *
     * @return array<array-key, mixed>
     */
    public function toDatabase(NotifiableInterface $to): array;
}
