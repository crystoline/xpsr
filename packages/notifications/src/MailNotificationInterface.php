<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

use Crystoline\Xpsr\Mail\Mailer;
use Symfony\Component\Mime\Email;

/**
 * A notification sent by email, on the "mail" channel, to the recipient's "mail" address. Needs
 * crystoline/xpsr-mail.
 */
interface MailNotificationInterface extends NotificationInterface
{
    /**
     * The email, such as from $mailer->compose('emails.payment-received', [...]). Without a To,
     * it goes to the recipient's address; without a From, it gets mail.from.
     */
    public function toMail(NotifiableInterface $to, Mailer $mailer): Email;
}
