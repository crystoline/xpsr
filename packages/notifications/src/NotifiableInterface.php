<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * Someone notifications go to, such as a user:
 *
 *     final class User extends Model implements UserInterface, NotifiableInterface
 *     {
 *         public function notificationAddress(string $channel): ?string
 *         {
 *             return match ($channel) {
 *                 'mail' => $this->email,
 *                 'sms' => $this->phone,
 *                 default => null,
 *             };
 *         }
 *
 *         public function notifiableKey(): NotifiableKey
 *         {
 *             return new NotifiableKey(self::class, $this->id);
 *         }
 *     }
 *
 * For someone without an account, such as a phone number signing up, use Recipient.
 */
interface NotifiableInterface
{
    /**
     * Where $channel delivers to this recipient: an email address for "mail", a phone number for
     * "sms", or what your own channel needs. Null skips the channel for this recipient.
     */
    public function notificationAddress(string $channel): ?string;

    /**
     * Who this recipient is in the notifications and push_subscriptions tables, which the database
     * and webpush channels need; null for a recipient without one, which skips those channels.
     */
    public function notifiableKey(): ?NotifiableKey;
}
