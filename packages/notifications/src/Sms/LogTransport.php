<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Notifications\Sms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * log://default: writes each SMS to the application log instead of sending it, for development.
 */
final class LogTransport implements TransportInterface
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

    public function send(MessageInterface $message): SentMessage
    {
        if ($message instanceof SmsMessage) {
            $this->logger->info('SMS to {to}: {text}', ['to' => $message->getPhone(), 'from' => $message->getFrom(), 'text' => $message->getSubject()]);
        }

        return new SentMessage($message, (string) $this);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage;
    }

    public function __toString(): string
    {
        return 'log://default';
    }
}
