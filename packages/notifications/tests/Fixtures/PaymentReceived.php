<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Notifications\DatabaseNotificationInterface;
use Crystoline\Xpsr\Notifications\MailNotificationInterface;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Crystoline\Xpsr\Notifications\SmsNotificationInterface;
use Crystoline\Xpsr\Notifications\WebPushMessage;
use Crystoline\Xpsr\Notifications\WebPushNotificationInterface;
use Symfony\Component\Mime\Email;

/** A notification on every built-in channel; its "payment" is an object, as an entity would be. */
final class PaymentReceived implements MailNotificationInterface, SmsNotificationInterface, DatabaseNotificationInterface, WebPushNotificationInterface
{
    public function __construct(public readonly Payment $payment) {}

    public function toMail(NotifiableInterface $to, Mailer $mailer): Email
    {
        return $mailer->compose('emails.payment', ['payment' => $this->payment])->subject('Payment received');
    }

    public function toSms(NotifiableInterface $to): string
    {
        return "You received NGN {$this->payment->amount}. Ref {$this->payment->reference}";
    }

    public function toDatabase(NotifiableInterface $to): array
    {
        return ['reference' => $this->payment->reference, 'amount' => $this->payment->amount, 'note' => 'Ẹ ṣé / thanks'];
    }

    public function toWebPush(NotifiableInterface $to): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Payment received',
            body: "NGN {$this->payment->amount}",
            icon: '/icons/payment.png',
            data: ['url' => "/payments/{$this->payment->reference}"],
            actions: [['action' => 'view', 'title' => 'View']],
            ttl: '1 day',
            urgency: 'high',
            topic: 'payments',
        );
    }
}
