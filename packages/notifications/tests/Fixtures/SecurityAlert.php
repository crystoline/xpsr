<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Notifications\MailNotificationInterface;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\SmsNotificationInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Message\SmsMessage;

/** Chooses its channels with channels(); its SMS is a Symfony SmsMessage with a sender ID. */
final class SecurityAlert implements MailNotificationInterface, SmsNotificationInterface
{
    /** @param mixed $channels what channels() returns */
    public function __construct(private readonly mixed $channels) {}

    public function channels(NotifiableInterface $to): mixed
    {
        return $this->channels;
    }

    public function toMail(NotifiableInterface $to, Mailer $mailer): Email
    {
        return (new Email())->to('security@example.com')->subject('New sign-in')->text('A new device signed in.');
    }

    public function toSms(NotifiableInterface $to): SmsMessage
    {
        return new SmsMessage((string) $to->notificationAddress('sms'), 'A new device signed in.', 'MPAY');
    }
}
