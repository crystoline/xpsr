<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Tests\Fixtures;

use Crystoline\Xpsr\Mail\Mailer;
use Crystoline\Xpsr\Notifications\MailNotificationInterface;
use Crystoline\Xpsr\Notifications\NotifiableInterface;
use Symfony\Component\Mime\Email;

/** On the custom slack channel and by email; "offline" in the text makes the Slack delivery fail. */
final class Deploy implements SlackNotificationInterface, MailNotificationInterface
{
    public function __construct(private readonly string $text) {}

    public function toSlack(NotifiableInterface $to): string
    {
        return $this->text;
    }

    public function toMail(NotifiableInterface $to, Mailer $mailer): Email
    {
        return (new Email())->subject('Deploy')->text($this->text);
    }
}
