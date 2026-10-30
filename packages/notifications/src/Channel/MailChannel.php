<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Channel;

use Closure;
use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Notifications\ChannelInterface;
use Crystoline\Xpsr\Notifications\Delivery;
use Crystoline\Xpsr\Notifications\MailNotificationInterface;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\NotificationInterface;
use LogicException;
use Symfony\Component\Mime\Email;

/**
 * The "mail" channel: MailNotificationInterface's email, sent through crystoline/xpsr-mail.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MailChannel implements ChannelInterface
{
    /** @param Closure(): Mailer $mailer */
    public function __construct(private readonly Closure $mailer) {}

    public function supports(NotificationInterface $notification): bool
    {
        return $notification instanceof MailNotificationInterface;
    }

    public function message(NotificationInterface $notification, NotifiableInterface $to, string $channel): ?Email
    {
        if (!$notification instanceof MailNotificationInterface) {
            throw new LogicException($notification::class . ' does not implement MailNotificationInterface.');
        }
        $address = $to->notificationAddress($channel);
        if ($address === null) {
            return null;
        }
        $email = $notification->toMail($to, ($this->mailer)());
        if ($email->getTo() === []) {
            $email->to($address);
        }

        return $email;
    }

    public function deliver(Delivery $delivery): void
    {
        if (!$delivery->message instanceof Email) {
            throw new LogicException('The mail channel delivers Symfony Email messages, not ' . get_debug_type($delivery->message) . '.');
        }
        ($this->mailer)()->send($delivery->message);
    }
}
