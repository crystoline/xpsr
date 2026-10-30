<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications;

/**
 * A notification. The channel interfaces it implements are the channels it goes to:
 *
 *     final class PaymentReceived implements MailNotificationInterface, SmsNotificationInterface
 *     {
 *         public function __construct(private readonly Payment $payment) {}
 *
 *         public function toMail(NotifiableInterface $to, Mailer $mailer): Email
 *         {
 *             return $mailer->compose('emails.payment-received', ['payment' => $this->payment])->subject('Payment received');
 *         }
 *
 *         public function toSms(NotifiableInterface $to): string
 *         {
 *             return "You received NGN {$this->payment->amount}. Ref {$this->payment->reference}";
 *         }
 *     }
 *
 * The channels are MailNotificationInterface, SmsNotificationInterface,
 * DatabaseNotificationInterface, WebPushNotificationInterface, and those in notifications.channels.
 * To choose among them for each recipient, such as by their preferences, add:
 *
 *     public function channels(NotifiableInterface $to): array   // list of channel names
 *
 * Its messages are made when it is sent or queued, and only they are queued, so its properties
 * can be entities.
 */
interface NotificationInterface {}
